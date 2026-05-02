<?php

namespace Uptimex\Client\Context;

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Root of a telemetry trace — every child event carries this trace_id and is
 * stitched together server-side via the per-tenant `traces` table.
 *
 * Three context types correspond to the three execution roots in a Laravel app:
 *   - request         (HTTP request)
 *   - command         (Artisan CLI command)
 *   - scheduled_task  (scheduler tick)
 *
 * UUIDv7 keeps trace ids time-sortable for cheap sequential scans on the server.
 */
final class ExecutionContext
{
    public const TYPE_REQUEST = 'request';

    public const TYPE_COMMAND = 'command';

    public const TYPE_SCHEDULED_TASK = 'scheduled_task';

    public readonly Carbon $startedAt;

    public function __construct(
        public readonly string $traceId,
        public readonly string $type,
        public readonly array $metadata = [],
    ) {
        $this->startedAt = Carbon::now();
    }

    public static function start(string $type, array $metadata = []): self
    {
        return new self(
            traceId: (string) Str::uuid7(),
            type: $type,
            metadata: $metadata,
        );
    }
}
