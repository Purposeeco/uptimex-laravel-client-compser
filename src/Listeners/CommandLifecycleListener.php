<?php

namespace Uptimex\Client\Listeners;

use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Symfony\Component\Console\Output\BufferedOutput;
use Throwable;
use Uptimex\Client\Context\ExecutionContext;
use Uptimex\Client\Uptimex;

/**
 * Owns the Artisan command lifecycle: starts a trace on `CommandStarting`,
 * records the `command` event + ends the trace on `CommandFinished`.
 *
 * The SDK's own `uptimex:*` commands are skipped — they self-flush, and the
 * long-running `uptimex:agent` would otherwise open a trace that never ends.
 * Laravel's `schedule:run` / `schedule:work` are skipped too: they run every
 * minute and would emit idle telemetry; the real scheduled tasks are captured
 * separately by ScheduledTaskLifecycleListener.
 */
final class CommandLifecycleListener
{
    /**
     * Laravel's scheduler dispatcher commands — `schedule:run` fires every
     * minute via cron; tracing it would emit an idle telemetry batch each
     * time. The real scheduled tasks are captured by
     * ScheduledTaskLifecycleListener regardless.
     */
    private const SKIP_EXACT = [
        'schedule:run',
        'schedule:work',
    ];

    /**
     * @var array<string, float>
     */
    private array $startedAtByName = [];

    public function __construct(private readonly Uptimex $uptimex) {}

    public function onStarting(CommandStarting $event): void
    {
        if (! $this->uptimex->shouldStartTrace() || $this->shouldSkip($event->command)) {
            return;
        }

        $name = $event->command ?? 'unknown';

        if ($this->uptimex->context() === null) {
            $this->uptimex->startTrace(ExecutionContext::TYPE_COMMAND, ['name' => $name]);
        }

        $this->startedAtByName[$name] = microtime(true);
    }

    public function onFinished(CommandFinished $event): void
    {
        if (! $this->uptimex->isEnabled()) {
            return;
        }

        try {
            if ($this->shouldSkip($event->command)) {
                return;
            }

            $name = $event->command ?? 'unknown';

            if ($this->uptimex->isRecording()) {
                $start = $this->startedAtByName[$name] ?? null;
                $durationMs = $start !== null ? (int) round((microtime(true) - $start) * 1000) : null;

                $arguments = method_exists($event->input, '__toString') ? (string) $event->input : null;

                $this->uptimex->record('command', [
                    'name' => mb_substr($name, 0, 190),
                    'arguments' => $arguments !== null ? ['raw' => mb_substr($arguments, 0, 2000)] : null,
                    'exit_code' => (int) $event->exitCode,
                    'output_excerpt' => $this->captureOutputExcerpt($event),
                    'duration_ms' => $durationMs,
                    'status' => $event->exitCode === 0 ? 'success' : 'failed',
                ]);
            }

            unset($this->startedAtByName[$name]);
        } finally {
            if ($this->uptimex->context()?->type === ExecutionContext::TYPE_COMMAND) {
                $this->uptimex->endTrace($event->exitCode === 0 ? 'ok' : 'failed');
            }
        }
    }

    /**
     * Commands the SDK must not trace — its own `uptimex:*` commands and the
     * scheduler dispatchers (see the class docblock).
     */
    private function shouldSkip(?string $command): bool
    {
        return $command !== null
            && (str_starts_with($command, 'uptimex:') || in_array($command, self::SKIP_EXACT, true));
    }

    /**
     * Capture the last 4 KB of stdout/stderr if the command was run with a
     * `BufferedOutput` (which artisan tests + many CLI scripts use). Streams
     * that aren't buffered (default Symfony ConsoleOutput) return null.
     */
    private function captureOutputExcerpt(CommandFinished $event): ?string
    {
        try {
            $output = $event->output;
            if ($output instanceof BufferedOutput) {
                $content = (string) $output->fetch();

                return mb_substr($content, max(0, mb_strlen($content) - 4096), 4096);
            }
        } catch (Throwable) {
            // Swallow.
        }

        return null;
    }
}
