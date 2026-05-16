<?php

namespace Uptimex\Client\Console;

use Illuminate\Console\Command;
use Uptimex\Client\Drain\DrainBudget;
use Uptimex\Client\Drain\Drainer;

/**
 * Ships everything currently waiting in the spool to UptimeX.
 *
 * The SDK already self-drains on the app's own request traffic, so this
 * command is optional — it exists for low-traffic apps that want a
 * scheduler-driven drain, for deploy scripts, and for manual diagnostics.
 * It shares the drain lock with the opportunistic drainer, so the two can
 * never double-process a batch.
 */
class SpoolDrainCommand extends Command
{
    protected $signature = 'uptimex:spool:drain
        {--once : Run a single drain pass instead of draining until the spool stops shrinking}
        {--max-batches=200 : Batches to ship per pass}';

    protected $description = 'Ship spooled UptimeX telemetry batches to the server.';

    public function handle(Drainer $drainer): int
    {
        if (! config('uptimex.enabled', true) || empty(config('uptimex.token'))) {
            $this->warn('UptimeX is not configured — nothing to drain.');

            return self::SUCCESS;
        }

        $budget = new DrainBudget(
            maxBatches: max(1, (int) $this->option('max-batches')),
            maxSeconds: 30.0,
        );

        $sent = 0;
        $failed = 0;
        $remaining = 0;

        do {
            $result = $drainer->drain($budget);
            $sent += $result->sent;
            $failed += $result->failed;
            $remaining = $result->remaining;

            if ($result->lockContended) {
                $this->info('Another drainer is already running — exiting.');
                break;
            }
        } while (! $this->option('once') && $result->sent > 0);

        $this->line("Drained: <info>{$sent}</info> sent, <comment>{$failed}</comment> failed, {$remaining} remaining.");

        return self::SUCCESS;
    }
}
