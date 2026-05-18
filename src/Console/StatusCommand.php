<?php

namespace Uptimex\Client\Console;

use Illuminate\Console\Command;
use Uptimex\Client\Agent\AgentClient;
use Uptimex\Client\Uptimex;

class StatusCommand extends Command
{
    protected $signature = 'uptimex:status';

    protected $description = 'Print the SDK config + show whether the agent is reachable.';

    public function handle(Uptimex $uptimex, AgentClient $agent): int
    {
        $delivery = (string) config('uptimex.delivery', 'agent');

        $rows = [
            ['enabled', config('uptimex.enabled') ? 'yes' : 'no'],
            ['token configured', config('uptimex.token') ? 'yes' : 'no'],
            ['ingest_url', (string) config('uptimex.ingest_url')],
            ['delivery', $delivery],
            ['deploy', (string) (config('uptimex.deploy') ?: '(unset)')],
            ['server', (string) (config('uptimex.server') ?: gethostname())],
            ['event_buffer', (string) config('uptimex.event_buffer')],
            ['flush_timeout', (string) config('uptimex.flush_timeout').'s'],
            ['sdk_version', (string) config('uptimex.sdk_version')],
            ['effective state', $uptimex->isEnabled() ? 'recording' : 'no-op'],
        ];

        if ($delivery === 'agent') {
            $rows[] = ['agent address', (string) config('uptimex.agent_address', '127.0.0.1:9237')];
            $rows[] = ['agent reachable', $agent->ping() ? 'yes' : 'no — falling back to direct send'];
        }

        $this->table(['Setting', 'Value'], $rows);

        return self::SUCCESS;
    }
}
