<?php

namespace Uptimex\Client\Transport;

/**
 * Implementations ship a serialized batch of events to the UptimeX server.
 * Returns true on a 2xx response, false otherwise — failures must never throw
 * into the host application's request lifecycle.
 *
 * @phpstan-type Batch array{
 *     batch_uuid?: string,
 *     sdk_version?: string,
 *     host?: string,
 *     events: list<array<string, mixed>>,
 * }
 */
interface Transport
{
    /**
     * @param  Batch  $batch
     */
    public function send(array $batch): bool;

    /**
     * Phase 8: send a deploy notification to `/api/ingest/deploy`. Implementations
     * may share Bearer-auth semantics with `send()` but use a different URL.
     * Returns the parsed response (deployment_id + idempotent flag) on 2xx,
     * or null on failure.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>|null
     */
    public function sendDeploy(array $payload): ?array;
}
