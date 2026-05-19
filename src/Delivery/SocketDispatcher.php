<?php

namespace Uptimex\Client\Delivery;

use Throwable;
use Uptimex\Client\Agent\AgentClient;

/**
 * The delivery dispatcher: hands each finished batch to the local
 * `uptimex:agent` daemon over a loopback socket — a microsecond-scale handoff
 * with no network and no files on the request path. The daemon ships it.
 *
 * If the agent is unreachable (not running, refused, slow, write error) the
 * batch is dropped — silently, with no log line. The SDK's circuit breaker
 * ({@see \Uptimex\Client\Support\AgentGate}) normally stops capture before a
 * trace is even started when the agent is down, so this path is reached only
 * in the rare race where the agent dies mid-request. Either way the host
 * request is never affected and this never throws.
 */
final class SocketDispatcher implements BatchDispatcher
{
    public function __construct(
        private readonly AgentClient $agent,
    ) {}

    public function dispatch(TelemetryBatch $batch): bool
    {
        if ($batch->isEmpty()) {
            return true;
        }

        try {
            $payload = json_encode($batch->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

            return $this->agent->send($payload);
        } catch (Throwable) {
            // Encode failure, or the agent went away mid-request. Drop the
            // batch silently — telemetry must never break or noise up the host.
            return false;
        }
    }
}
