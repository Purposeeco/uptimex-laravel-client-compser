<?php

namespace Uptimex\Client\Delivery;

use Illuminate\Support\Facades\Log;
use Throwable;
use Uptimex\Client\Spool\Spool;
use Uptimex\Client\Spool\SpooledBatch;

/**
 * Default dispatcher: writes the finished batch to the durable {@see Spool}
 * — a fast local file write, with no network on the request path.
 *
 * If the spool write fails (e.g. a read-only filesystem) it falls back to a
 * direct send, so telemetry is degraded rather than silently dropped and
 * the host request is never affected. The fallback is warned about once
 * per process, not per request.
 */
final class SpoolingDispatcher implements BatchDispatcher
{
    private bool $warned = false;

    public function __construct(
        private readonly Spool $spool,
        private readonly DirectDispatcher $fallback,
    ) {}

    public function dispatch(SpooledBatch $batch): bool
    {
        if ($batch->isEmpty()) {
            return true;
        }

        try {
            $this->spool->write($batch);

            return true;
        } catch (Throwable $e) {
            $this->warnOnce($e);

            return $this->fallback->dispatch($batch);
        }
    }

    private function warnOnce(Throwable $e): void
    {
        if ($this->warned) {
            return;
        }

        $this->warned = true;
        Log::warning('uptimex.spool.unwritable', ['exception' => $e->getMessage()]);
    }
}
