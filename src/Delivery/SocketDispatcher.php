<?php

namespace Uptimex\Client\Delivery;

use Illuminate\Support\Facades\Log;
use Throwable;
use Uptimex\Client\Agent\AgentClient;
use Uptimex\Client\Support\LogThrottle;

/**
 * The `agent` delivery dispatcher: hands the finished batch to the local
 * `uptimex:agent` over a loopback socket — a microsecond-scale handoff with
 * no network and no files on the request path.
 *
 * If the agent is unreachable (not running, refused, slow, write error) and
 * a fallback {@see DirectDispatcher} is set, it degrades to a direct HTTPS
 * send so telemetry is still delivered. With no fallback (`UPTIMEX_AGENT_FALLBACK`
 * = false — strict agent-only mode) the batch is dropped instead. Either way
 * the host request is never affected and this never throws.
 */
final class SocketDispatcher implements BatchDispatcher
{
    private bool $warned = false;

    public function __construct(
        private readonly AgentClient $agent,
        private readonly ?DirectDispatcher $fallback,
    ) {}

    public function dispatch(TelemetryBatch $batch): bool
    {
        if ($batch->isEmpty()) {
            return true;
        }

        try {
            $payload = json_encode($batch->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

            if ($this->agent->send($payload)) {
                return true; // handed off to the agent
            }
        } catch (Throwable $e) {
            $this->warnOnce($e->getMessage());
        }

        // Agent unreachable, or the batch could not be encoded.
        if ($this->fallback !== null) {
            // Degrade to a direct send rather than dropping the telemetry.
            return $this->fallback->dispatch($batch);
        }

        // Strict agent-only mode (UPTIMEX_AGENT_FALLBACK=false): the agent is
        // the only path, so the batch is dropped — never sent inline over HTTP
        // from the request. Logged once per cooldown so the operator can see it.
        LogThrottle::warn(
            'uptimex.agent.dropped_no_fallback',
            'uptimex.agent.dropped_no_fallback',
            ['reason' => 'agent unreachable and UPTIMEX_AGENT_FALLBACK is disabled'],
        );

        return false;
    }

    private function warnOnce(string $reason): void
    {
        if ($this->warned) {
            return;
        }

        $this->warned = true;
        Log::warning('uptimex.agent.dispatch_failed', ['reason' => $reason]);
    }
}
