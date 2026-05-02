<?php

namespace Uptimex\Client\Listeners;

use Illuminate\Database\Events\QueryExecuted;
use Uptimex\Client\Sql\SqlNormalizer;
use Uptimex\Client\Uptimex;

/**
 * Listens to `Illuminate\Database\Events\QueryExecuted` and records a
 * normalized version of every SQL statement (bindings stripped, whitespace
 * collapsed) under the active trace. Bindings are NEVER captured — privacy
 * by default.
 */
final class QueryListener
{
    public function __construct(private readonly Uptimex $uptimex) {}

    public function handle(QueryExecuted $event): void
    {
        if (! $this->uptimex->isEnabled() || $this->uptimex->context() === null) {
            return;
        }

        $normalized = SqlNormalizer::normalize((string) $event->sql);

        $this->uptimex->record('query', [
            'duration_ms' => (int) round($event->time),
            'connection_name' => $event->connectionName,
            'sql_normalized' => $normalized,
            'sql_hash' => sha1($normalized),
        ]);
    }
}
