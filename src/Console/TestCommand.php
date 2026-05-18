<?php

namespace Uptimex\Client\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Uptimex\Client\Transport\Transport;

class TestCommand extends Command
{
    protected $signature = 'uptimex:test';

    protected $description = 'Send a synthetic event batch to UptimeX and print the result.';

    /**
     * Always performs a real, synchronous round-trip to the server —
     * regardless of the configured `delivery` mode — so the command
     * genuinely verifies the wire (token, URL, connectivity) rather than
     * just confirming a batch was handed to the local agent.
     */
    public function handle(Transport $transport): int
    {
        if (! config('uptimex.enabled', true) || empty(config('uptimex.token'))) {
            $this->error('UptimeX is not configured. Set UPTIMEX_TOKEN in your environment.');

            return self::FAILURE;
        }

        $this->info('Sending synthetic batch to '.config('uptimex.ingest_url').' ...');

        $traceId = (string) Str::uuid7();

        $ok = $transport->send([
            'batch_uuid' => (string) Str::uuid(),
            'sdk_version' => (string) config('uptimex.sdk_version', '0.1.0'),
            'host' => config('uptimex.server') ?: (gethostname() ?: null),
            'events' => [[
                'type' => 'request',
                'trace_id' => $traceId,
                'occurred_at' => now()->toIso8601String(),
                'duration_ms' => 1,
                'source' => 'uptimex:test command',
            ]],
        ]);

        if ($ok) {
            $this->info('Batch accepted by UptimeX.');
            $this->line('  trace_id: '.$traceId);

            return self::SUCCESS;
        }

        $this->error('Batch failed. Check storage/logs for `uptimex.transport.*` warnings.');

        return self::FAILURE;
    }
}
