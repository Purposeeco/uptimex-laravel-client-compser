<?php

namespace Uptimex\Client\Console;

use Illuminate\Console\Command;
use Uptimex\Client\Context\ExecutionContext;
use Uptimex\Client\Uptimex;

class TestCommand extends Command
{
    protected $signature = 'uptimex:test';

    protected $description = 'Send a synthetic event batch to UptimeX and print the result.';

    public function handle(Uptimex $uptimex): int
    {
        if (! $uptimex->isEnabled()) {
            $this->error('UptimeX is not configured. Set UPTIMEX_TOKEN in your environment.');

            return self::FAILURE;
        }

        $this->info('Sending synthetic batch to '.config('uptimex.ingest_url').' ...');

        $context = $uptimex->startTrace(ExecutionContext::TYPE_REQUEST, [
            'source' => 'uptimex:test command',
        ]);

        $uptimex->record('request', [
            'duration_ms' => 1,
        ]);

        $ok = $uptimex->endTrace('ok');

        if ($ok) {
            $this->info('Batch accepted by UptimeX.');
            $this->line('  trace_id: '.$context->traceId);

            return self::SUCCESS;
        }

        $this->error('Batch failed. Check storage/logs for `uptimex.transport.*` warnings.');

        return self::FAILURE;
    }
}
