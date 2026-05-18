<?php

namespace Uptimex\Client\Delivery;

use Illuminate\Support\Facades\Log;
use Throwable;
use Uptimex\Client\Agent\AgentClient;

/**
 * Default dispatcher: hands the finished batch to the local `uptimex:agent`
 * over a loopback socket — a microsecond-scale handoff with no network and
 * no files on the request path.
 *
 * If the agent is unreachable (not running, refused, slow, write error) it
 * degrades to a direct HTTPS send via the injected {@see DirectDispatcher},
 * so telemetry is still delivered best-effort rather than dropped. The host
 * request is never affected and this never throws. (Agent-absent is a
 * supported mode — `uptimex:status` reports reachability — so it is not
 * logged; only a genuine encode error is, once per process.)
 */
final class SocketDispatcher implements BatchDispatcher
{
    private bool $warned = false;

    public function __construct(
        private readonly AgentClient $agent,
        private readonly DirectDispatcher $fallback,
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

        // Agent unreachable, or the batch could not be encoded — degrade to
        // a direct send rather than dropping the telemetry.
        return $this->fallback->dispatch($batch);
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
