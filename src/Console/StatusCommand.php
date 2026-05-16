<?php

namespace Uptimex\Client\Console;

use Illuminate\Console\Command;
use Uptimex\Client\Spool\Spool;
use Uptimex\Client\Uptimex;

class StatusCommand extends Command
{
    protected $signature = 'uptimex:status';

    protected $description = 'Print the SDK config + show whether ingest is reachable.';

    public function handle(Uptimex $uptimex): int
    {
        $rows = [
            ['enabled', config('uptimex.enabled') ? 'yes' : 'no'],
            ['token configured', config('uptimex.token') ? 'yes' : 'no'],
            ['ingest_url', (string) config('uptimex.ingest_url')],
            ['delivery', (string) config('uptimex.delivery', 'spool')],
            ['deploy', (string) (config('uptimex.deploy') ?: '(unset)')],
            ['server', (string) (config('uptimex.server') ?: gethostname())],
            ['event_buffer', (string) config('uptimex.event_buffer')],
            ['flush_timeout', (string) config('uptimex.flush_timeout').'s'],
            ['sdk_version', (string) config('uptimex.sdk_version')],
            ['effective state', $uptimex->isEnabled() ? 'recording' : 'no-op'],
        ];

        if (config('uptimex.delivery', 'spool') === 'spool') {
            $rows[] = ['spool path', (string) (config('uptimex.spool_path') ?: storage_path('uptimex/spool'))];
            $rows[] = ['spool pending', (string) app(Spool::class)->size()];
        }

        $this->table(['Setting', 'Value'], $rows);

        return self::SUCCESS;
    }
}
