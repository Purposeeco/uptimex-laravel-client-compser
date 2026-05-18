<?php

namespace Uptimex\Client;

use Closure;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Facades\Context as LaravelContext;
use Illuminate\Support\Str;
use Throwable;
use Uptimex\Client\Buffer\EventBuffer;
use Uptimex\Client\Context\ExecutionContext;
use Uptimex\Client\Delivery\BatchDispatcher;
use Uptimex\Client\Delivery\TelemetryBatch;

/**
 * The SDK's public service. Holds the active execution context (one per
 * HTTP request / Artisan command / scheduled tick), the buffer of pending
 * events, and the transport that flushes them to the UptimeX server.
 *
 * The Uptimex facade proxies straight here.
 */
class Uptimex
{
    private ?ExecutionContext $context = null;

    private ?EventBuffer $buffer = null;

    private int $pauseDepth = 0;

    private bool $flushed = false;

    private ?float $traceSampleRate = null;

    /**
     * Per-event-type rejection callbacks registered by the host application.
     *
     * @var array<string, list<Closure>>
     */
    private array $rejectCallbacks = [];

    /**
     * Per-event-type redaction callbacks registered by the host application.
     *
     * @var array<string, list<Closure>>
     */
    private array $redactCallbacks = [];

    public function __construct(
        private readonly ConfigRepository $config,
        private readonly BatchDispatcher $dispatcher,
    ) {}

    public function isEnabled(): bool
    {
        return (bool) $this->config->get('uptimex.enabled', true)
            && ! empty($this->config->get('uptimex.token'));
    }

    /**
     * Whether events recorded right now will actually be captured — the SDK
     * is enabled, a trace is open, and capture is not paused (a sampled-out
     * trace counts as paused). Listeners call this BEFORE building a payload
     * so a sampled-out trace costs next to nothing.
     */
    public function isRecording(): bool
    {
        return $this->isEnabled()
            && $this->context !== null
            && ! $this->isPaused();
    }

    public function context(): ?ExecutionContext
    {
        return $this->context;
    }

    public function buffer(): ?EventBuffer
    {
        return $this->buffer;
    }

    public function sampleRate(): ?float
    {
        return $this->traceSampleRate;
    }

    /**
     * Begin a new execution context (trace root). Sampling decision is made
     * here — once per trace — and child events inherit. The chosen rate is
     * stored on the trace so server-side aggregations can multiply by 1/rate.
     */
    public function startTrace(string $type, array $metadata = []): ExecutionContext
    {
        $rate = $this->resolveSampleRate($type);

        $this->context = ExecutionContext::start($type, $metadata);
        $this->buffer = new EventBuffer((int) $this->config->get('uptimex.event_buffer', 500));
        $this->pauseDepth = 0;
        $this->flushed = false;
        $this->traceSampleRate = $rate;

        // If the trace is sampled out at start time, capture is paused for
        // the entire context. We still keep the context object so child
        // listeners' calls to `record()` are no-ops without firing errors.
        if (! $this->shouldKeep($rate)) {
            $this->pauseDepth = 1;
        }

        return $this->context;
    }

    /**
     * Override the active trace's sample rate at runtime. Useful for "force
     * capture" — a controller noticing a suspicious request can call
     * `Uptimex::sample(1.0)` to ensure full child events.
     */
    public function sample(float $rate): void
    {
        $rate = max(0.0, min(1.0, $rate));
        $this->traceSampleRate = $rate;

        // Resume capture if a previously-sampled-out trace is being boosted.
        if ($rate > 0.0 && $this->pauseDepth === 1 && $this->buffer !== null && $this->buffer->isEmpty()) {
            $this->pauseDepth = 0;
        }
    }

    /**
     * Close the active execution context and flush its buffer to the transport.
     * Safe to call multiple times — subsequent calls are no-ops.
     */
    public function endTrace(string $status = 'ok'): bool
    {
        if ($this->flushed || $this->context === null || $this->buffer === null) {
            return false;
        }

        $this->flushed = true;

        if (! $this->isEnabled() || $this->buffer->isEmpty()) {
            $this->resetContext();

            return true;
        }

        $batch = new TelemetryBatch(
            batchUuid: (string) Str::uuid(),
            sdkVersion: (string) $this->config->get('uptimex.sdk_version', '0.1.0'),
            host: $this->config->get('uptimex.server') ?: (gethostname() ?: null),
            sampleRate: $this->traceSampleRate,
            context: $this->snapshotContext(),
            events: $this->buffer->flush(),
        );

        // Hand the finished batch to the configured delivery strategy
        // (the local agent by default). The dispatcher never throws.
        $accepted = $this->dispatcher->dispatch($batch);

        $this->resetContext();

        return $accepted;
    }

    /**
     * Record an event under the active execution context.
     *
     * No-ops when:
     *   - the SDK is disabled,
     *   - capture is paused (including sampled-out traces),
     *   - the active event type is fully ignored via env var,
     *   - no execution context is active,
     *   - or a registered `reject*()` callback opts to drop this event.
     *
     * Registered `redact*()` callbacks run last and may mutate the payload.
     */
    public function record(string $type, array $payload = []): void
    {
        if (! $this->isEnabled() || $this->isPaused() || $this->context === null || $this->buffer === null) {
            return;
        }

        if ($this->isCategoryIgnored($type)) {
            return;
        }

        if ($this->isRejected($type, $payload)) {
            return;
        }

        $payload = $this->applyRedactions($type, $payload);

        $this->buffer->add(array_merge([
            'type' => $type,
            'trace_id' => $this->context->traceId,
            'occurred_at' => now()->toIso8601String(),
        ], $payload));
    }

    public function pause(): void
    {
        $this->pauseDepth++;
    }

    public function resume(): void
    {
        $this->pauseDepth = max(0, $this->pauseDepth - 1);
    }

    public function isPaused(): bool
    {
        return $this->pauseDepth > 0;
    }

    /**
     * Run $callback with capture paused; capture is restored even if the
     * callback throws. Returns whatever the callback returns.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function ignore(callable $callback): mixed
    {
        $this->pause();
        try {
            return $callback();
        } finally {
            $this->resume();
        }
    }

    /**
     * Register a callback that decides whether to drop a given event of
     * `$type`. Return `true` from the callback to drop. Multiple callbacks
     * for the same type are combined with OR — any returning true drops.
     */
    public function reject(string $type, Closure $callback): self
    {
        $this->rejectCallbacks[$type][] = $callback;

        return $this;
    }

    /**
     * Sugar for the common type-specific reject calls. Each accepts the same
     * `Closure(array $payload): bool` signature.
     */
    public function rejectQueries(Closure $cb): self
    {
        return $this->reject('query', $cb);
    }

    public function rejectQueuedJobs(Closure $cb): self
    {
        return $this->reject('job', $cb);
    }

    public function rejectMail(Closure $cb): self
    {
        return $this->reject('mail', $cb);
    }

    public function rejectNotifications(Closure $cb): self
    {
        return $this->reject('notification', $cb);
    }

    public function rejectCacheKeys(Closure $cb): self
    {
        return $this->reject('cache', $cb);
    }

    public function rejectOutgoingRequests(Closure $cb): self
    {
        return $this->reject('outgoing_request', $cb);
    }

    /**
     * Register a callback that mutates the event payload before it lands in
     * the buffer. Useful for masking sensitive fields beyond the built-in
     * defaults. Return the (possibly modified) payload array.
     */
    public function redact(string $type, Closure $callback): self
    {
        $this->redactCallbacks[$type][] = $callback;

        return $this;
    }

    public function redactHeaders(Closure $cb): self
    {
        return $this->redact('request', function (array $payload) use ($cb): array {
            if (isset($payload['headers']) && is_array($payload['headers'])) {
                $payload['headers'] = $cb($payload['headers']);
            }

            return $payload;
        });
    }

    public function redactPayload(Closure $cb): self
    {
        return $this->redact('request', function (array $payload) use ($cb): array {
            if (isset($payload['payload']) && is_array($payload['payload'])) {
                $payload['payload'] = $cb($payload['payload']);
            }

            return $payload;
        });
    }

    public function redactMail(Closure $cb): self
    {
        return $this->redact('mail', function (array $payload) use ($cb): array {
            if (isset($payload['subject']) && is_string($payload['subject'])) {
                $payload['subject'] = (string) $cb($payload['subject']);
            }

            return $payload;
        });
    }

    public function redactQueries(Closure $cb): self
    {
        return $this->redact('query', function (array $payload) use ($cb): array {
            if (isset($payload['sql_normalized']) && is_string($payload['sql_normalized'])) {
                $payload['sql_normalized'] = (string) $cb($payload['sql_normalized']);
                $payload['sql_hash'] = sha1($payload['sql_normalized']);
            }

            return $payload;
        });
    }

    public function redactCacheKeys(Closure $cb): self
    {
        return $this->redact('cache', function (array $payload) use ($cb): array {
            if (isset($payload['key']) && is_string($payload['key'])) {
                $payload['key'] = (string) $cb($payload['key']);
            }

            return $payload;
        });
    }

    public function redactOutgoingRequests(Closure $cb): self
    {
        return $this->redact('outgoing_request', function (array $payload) use ($cb): array {
            return $cb($payload);
        });
    }

    public function redactLogs(Closure $cb): self
    {
        return $this->redact('log', function (array $payload) use ($cb): array {
            if (isset($payload['context']) && is_array($payload['context'])) {
                $payload['context'] = $cb($payload['context']);
            }

            return $payload;
        });
    }

    /**
     * Snapshot Laravel's `Context` facade into a JSON-serializable array,
     * truncated at the configured byte cap. Returns null if the Context
     * facade isn't bound (host app may have disabled it).
     */
    private function snapshotContext(): ?array
    {
        try {
            $all = LaravelContext::all();
        } catch (Throwable) {
            return null;
        }

        if ($all === [] || ! is_array($all)) {
            return null;
        }

        $maxBytes = (int) $this->config->get('uptimex.context_max_bytes', 65 * 1024);

        try {
            $encoded = json_encode($all, JSON_UNESCAPED_SLASHES);
            if ($encoded !== false && strlen($encoded) <= $maxBytes) {
                return $all;
            }

            // Truncate by repeatedly dropping the last key until under cap.
            while ($all !== [] && strlen((string) json_encode($all, JSON_UNESCAPED_SLASHES)) > $maxBytes) {
                array_pop($all);
            }

            return ['__truncated' => true, ...$all];
        } catch (Throwable) {
            return null;
        }
    }

    private function resolveSampleRate(string $contextType): float
    {
        return match ($contextType) {
            ExecutionContext::TYPE_REQUEST => (float) $this->config->get('uptimex.request_sample_rate', 1.0),
            ExecutionContext::TYPE_COMMAND => (float) $this->config->get('uptimex.command_sample_rate', 1.0),
            ExecutionContext::TYPE_SCHEDULED_TASK => (float) $this->config->get('uptimex.scheduled_task_sample_rate', 1.0),
            default => 1.0,
        };
    }

    private function shouldKeep(float $rate): bool
    {
        if ($rate >= 1.0) {
            return true;
        }
        if ($rate <= 0.0) {
            return false;
        }

        return mt_rand() / mt_getrandmax() < $rate;
    }

    private function isCategoryIgnored(string $type): bool
    {
        return match ($type) {
            'query' => (bool) $this->config->get('uptimex.ignore_queries', false),
            'cache' => (bool) $this->config->get('uptimex.ignore_cache_events', false),
            'mail' => (bool) $this->config->get('uptimex.ignore_mail', false),
            'notification' => (bool) $this->config->get('uptimex.ignore_notifications', false),
            'outgoing_request' => (bool) $this->config->get('uptimex.ignore_outgoing_requests', false),
            default => false,
        };
    }

    private function isRejected(string $type, array $payload): bool
    {
        foreach ($this->rejectCallbacks[$type] ?? [] as $callback) {
            try {
                if ((bool) $callback($payload)) {
                    return true;
                }
            } catch (Throwable) {
                // Don't let a buggy filter break ingestion — fail-open.
            }
        }

        return false;
    }

    private function applyRedactions(string $type, array $payload): array
    {
        foreach ($this->redactCallbacks[$type] ?? [] as $callback) {
            try {
                $payload = $callback($payload) ?? $payload;
            } catch (Throwable) {
                // Fail-open: keep the original payload.
            }
        }

        return $payload;
    }

    private function resetContext(): void
    {
        $this->context = null;
        $this->buffer = null;
        $this->traceSampleRate = null;
    }
}
