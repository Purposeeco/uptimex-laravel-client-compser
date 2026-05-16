<?php

namespace Uptimex\Client\Console;

use Illuminate\Console\Command;
use Uptimex\Client\Transport\Transport;

/**
 * Phase 8: invoked from CI / deployment tooling in monitored apps to record a
 * deploy. Reads UPTIMEX_TOKEN from the env. The server stores the deploy and
 * sweeps "resolve on next deploy" issues for the env.
 */
class DeployCommand extends Command
{
    protected $signature = 'uptimex:deploy
        {reference : Identifier for this deploy (e.g. commit sha or version tag)}
        {--name= : Human-friendly name (e.g. "v2.5.0")}
        {--url= : Link to the deploy artifact / changelog}
        {--metadata=* : key=value pairs of arbitrary metadata}';

    protected $description = 'Notify UptimeX of a new deployment for the configured environment.';

    public function handle(Transport $transport): int
    {
        if (! config('uptimex.enabled', true) || empty(config('uptimex.token'))) {
            $this->error('UptimeX is not configured. Set UPTIMEX_TOKEN.');

            return self::FAILURE;
        }

        $payload = [
            'reference' => (string) $this->argument('reference'),
            'deployed_at' => now()->toIso8601String(),
        ];

        if ($name = $this->option('name')) {
            $payload['name'] = (string) $name;
        }
        if ($url = $this->option('url')) {
            $payload['url'] = (string) $url;
        }

        $metadata = [];
        foreach ((array) $this->option('metadata') as $pair) {
            if (! str_contains((string) $pair, '=')) {
                continue;
            }
            [$key, $value] = explode('=', (string) $pair, 2);
            $metadata[trim($key)] = trim($value);
        }
        if ($metadata !== []) {
            $payload['metadata'] = $metadata;
        }

        $response = $transport->sendDeploy($payload);

        if ($response === null) {
            $this->error('Deploy notification failed. Check storage/logs for `uptimex.deploy.*` warnings.');

            return self::FAILURE;
        }

        $this->info('Deploy registered.');
        if (isset($response['deployment_id'])) {
            $this->line('  deployment_id: '.$response['deployment_id']);
        }
        if (! empty($response['idempotent'])) {
            $this->line('  (already known — idempotent re-send)');
        }

        return self::SUCCESS;
    }
}
