<?php

namespace Uptimex\Client\Console;

use Illuminate\Console\Command;
use Uptimex\Client\Agent\AgentClient;
use Uptimex\Client\Uptimex;

class StatusCommand extends Command
{
    protected $signature = 'uptimex:status';

    protected $description = 'Print the SDK config and show whether the agent is reachable.';

    public function handle(Uptimex $uptimex, AgentClient $agent): int
    {
        // Live probe — status should reflect the agent right now, not the
        // circuit breaker's possibly-stale cached verdict.
        $reachable = $agent->ping();

        $state = match (true) {
            ! $uptimex->isEnabled() => 'no-op (disabled or no token)',
            $reachable => 'recording',
            default => 'paused (agent unreachable)',
        };

        $this->table(['Setting', 'Value'], [
            ['enabled', config('uptimex.enabled') ? 'yes' : 'no'],
            ['token configured', config('uptimex.token') ? 'yes' : 'no'],
            ['ingest_url', (string) config('uptimex.ingest_url')],
            ['deploy', (string) (config('uptimex.deploy') ?: '(unset)')],
            ['server', (string) (config('uptimex.server') ?: gethostname())],
            ['event_buffer', (string) config('uptimex.event_buffer')],
            ['flush_timeout', (string) config('uptimex.flush_timeout').'s'],
            ['sdk_version', (string) config('uptimex.sdk_version')],
            ['agent address', (string) config('uptimex.agent_address', '127.0.0.1:9237')],
            ['agent reachable', $reachable ? 'yes' : 'no — telemetry is paused until the agent is running'],
            ['effective state', $state],
        ]);

        return self::SUCCESS;
    }
}
