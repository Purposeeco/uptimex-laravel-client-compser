<?php

namespace Uptimex\Client\Console;

use Illuminate\Console\Command;
use RuntimeException;
use Uptimex\Client\Agent\Agent;
use Uptimex\Client\Agent\BatchQueue;
use Uptimex\Client\Agent\Shipper;
use Uptimex\Client\Agent\SocketServer;
use Uptimex\Client\Support\Clock;
use Uptimex\Client\Transport\Transport;

/**
 * The `uptimex:agent` daemon. Run it as a long-lived process — a Forge
 * daemon or a Supervisor program — and it accepts telemetry batches from the
 * SDK over a local socket, buffers them in memory, and ships them to UptimeX,
 * retrying through outages and draining gracefully on a stop signal.
 *
 * It is the SDK's only delivery path: with no agent running the SDK captures
 * nothing — it behaves exactly as if disabled — and resumes once it is back.
 */
class AgentCommand extends Command
{
    protected $signature = 'uptimex:agent
        {--once : Run a single accept + drain pass and exit, instead of running forever}';

    protected $description = 'Run the UptimeX telemetry agent — buffers batches from the local socket and ships them to UptimeX.';

    public function handle(Transport $transport, Clock $clock): int
    {
        $address = (string) config('uptimex.agent_address', '127.0.0.1:9237');

        if (! config('uptimex.enabled', true) || empty(config('uptimex.token'))) {
            $this->warn('UptimeX has no token configured — the agent will idle. Set UPTIMEX_TOKEN and restart.');
        }

        $agent = new Agent(
            server: new SocketServer($address),
            queue: new BatchQueue((int) config('uptimex.agent_max_queue', 10000)),
            shipper: new Shipper(
                $transport,
                $clock,
                (int) config('uptimex.retry_base_seconds', 5),
                (int) config('uptimex.retry_max_seconds', 300),
            ),
            clock: $clock,
            shipBatchSize: (int) config('uptimex.agent_ship_batch_size', 20),
            log: fn (string $line) => $this->line('  '.date('Y-m-d H:i:s').'  '.$line),
        );

        try {
            $version = (string) config('uptimex.sdk_version', '0.1.0');
            $target = parse_url((string) config('uptimex.ingest_url'), PHP_URL_HOST) ?: 'UptimeX';
            $this->info("uptimex:agent started — listening on {$address} (v{$version}) — shipping to {$target}");

            return $this->option('once') ? $agent->runOnce() : $agent->run();
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());
            $this->line('Another uptimex:agent may already be running, or the address is taken — check UPTIMEX_AGENT_ADDRESS.');

            return self::FAILURE;
        }
    }
}
