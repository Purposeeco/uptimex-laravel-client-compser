<?php

namespace Uptimex\Client\Agent;

use Closure;
use Illuminate\Support\Facades\Log;
use Throwable;
use Uptimex\Client\Delivery\TelemetryBatch;
use Uptimex\Client\Support\Clock;

/**
 * The `uptimex:agent` daemon: a single-process cooperative event loop that
 * accepts framed batches on a local socket, queues them in memory, and ships
 * them to the UptimeX server — retrying through outages and draining
 * gracefully on a stop signal. No threads, no forks.
 */
final class Agent
{
    /** Event-loop select timeout — caps loop latency and prevents idle spin. */
    private const SELECT_TIMEOUT_US = 200_000; // 0.2 s

    /** Hard cap on the shutdown / runOnce drain. */
    private const SHUTDOWN_DRAIN_SECONDS = 10.0;

    private bool $shuttingDown = false;

    private bool $signalsInstalled = false;

    /** Set once the first batch is confirmed shipped — gates the "Connected" line. */
    private bool $connected = false;

    /**
     * @param  Closure(string):void|null  $log  Optional activity-line sink — the
     *                                          console command wires it to stdout.
     */
    public function __construct(
        private readonly SocketServer $server,
        private readonly BatchQueue $queue,
        private readonly Shipper $shipper,
        private readonly Clock $clock,
        private readonly int $shipBatchSize = 20,
        private readonly ?Closure $log = null,
    ) {}

    /**
     * Boot the socket, then loop until a stop signal. Returns the exit code.
     */
    public function run(): int
    {
        $this->server->listen();
        $this->installSignalHandlers();

        while (! $this->shuttingDown) {
            if ($this->signalsInstalled) {
                pcntl_signal_dispatch();
            }
            $this->tick();
        }

        $this->shutdown();

        return 0;
    }

    /**
     * Run a single accept + read pass, then drain and stop. Backs the
     * `--once` flag and the feature tests.
     */
    public function runOnce(): int
    {
        $this->server->listen();
        $this->tick();
        $this->shipper->drain($this->queue, $this->clock->monotonic() + self::SHUTDOWN_DRAIN_SECONDS);
        $this->server->close();

        return 0;
    }

    public function requestShutdown(): void
    {
        $this->shuttingDown = true;
    }

    /**
     * One event-loop iteration: select → accept → read+queue → reap → ship.
     */
    public function tick(): void
    {
        $now = $this->clock->monotonic();

        $read = array_merge(
            array_filter([$this->server->serverStream()], 'is_resource'),
            $this->server->connectionStreams(),
        );
        $write = [];
        $except = [];

        if ($read !== []) {
            $ready = @stream_select($read, $write, $except, 0, self::SELECT_TIMEOUT_US);
            if ($ready === false) {
                return; // interrupted by a signal — loop re-checks shutdown
            }
        }

        $this->server->acceptPending($now);

        foreach (array_values($this->server->connections()) as $connection) {
            $connection->pump();
            $frame = $connection->frame();
            if ($frame !== null) {
                $batch = $this->decodeFrame($frame);
                if ($batch !== null) {
                    $this->queue->push($batch);
                }
            }
            if ($connection->isDone()) {
                $this->server->drop($connection);
            }
        }

        $this->server->reapStalled($now);

        if (! $this->queue->isEmpty()
            && ! $this->shipper->transportIsNoop()
            && $this->shipper->readyToShip()) {
            $startedAt = $this->clock->monotonic();
            $report = $this->shipper->ship($this->queue, $this->shipBatchSize);
            $this->reportShip($report, $this->clock->monotonic() - $startedAt);
        }
    }

    private function shutdown(): void
    {
        $this->server->stopListening();

        $pending = $this->queue->count();
        if ($pending > 0) {
            $this->emit("Stopping — draining {$pending} batch(es)…");
        }

        $this->shipper->drain($this->queue, $this->clock->monotonic() + self::SHUTDOWN_DRAIN_SECONDS);
        $this->server->close();

        $remaining = $this->queue->count();
        if ($remaining > 0) {
            $this->warn('uptimex.agent.shutdown_undrained', ['remaining' => $remaining]);
            $this->emit("uptimex:agent stopped — {$remaining} batch(es) undrained");
        } else {
            $this->emit('uptimex:agent stopped');
        }
    }

    private function decodeFrame(string $payload): ?TelemetryBatch
    {
        try {
            $decoded = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            $this->warn('uptimex.agent.malformed_frame', ['bytes' => strlen($payload)]);

            return null;
        }

        if (! is_array($decoded)) {
            $this->warn('uptimex.agent.malformed_frame', ['bytes' => strlen($payload)]);

            return null;
        }

        return TelemetryBatch::fromArray($decoded);
    }

    private function installSignalHandlers(): void
    {
        if (! extension_loaded('pcntl')) {
            $this->warn('uptimex.agent.no_pcntl', [
                'note' => 'ext-pcntl is missing — a stop signal hard-kills the agent and '
                    .'loses the in-memory queue. Install ext-pcntl for graceful drain on deploy.',
            ]);

            return;
        }

        pcntl_async_signals(true);
        $handler = function (): void {
            $this->requestShutdown();
        };
        pcntl_signal(SIGTERM, $handler);
        pcntl_signal(SIGINT, $handler);
        $this->signalsInstalled = true;
    }

    /**
     * Emit the per-ship activity line — the agent's "Ingest successful" log.
     */
    private function reportShip(ShipReport $report, float $elapsed): void
    {
        if ($report->sent > 0) {
            $ms = max(0, (int) round($elapsed * 1000));
            if (! $this->connected) {
                $this->connected = true;
                $this->emit("Connected to UptimeX — first batch accepted [{$ms}ms]");
            } else {
                $this->emit("Ingest successful [{$ms}ms] — {$report->sent} batch(es)");
            }
        }

        if ($report->failed) {
            $this->emit("Ingest failed — {$report->queueDepth} batch(es) queued, retrying");
        }
    }

    /**
     * Write an activity line to the optional log sink. Never throws.
     */
    private function emit(string $message): void
    {
        if ($this->log === null) {
            return;
        }

        try {
            ($this->log)($message);
        } catch (Throwable) {
            // Logging must never crash the agent loop.
        }
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function warn(string $message, array $context = []): void
    {
        try {
            Log::warning($message, $context);
        } catch (Throwable) {
            // Logging must never crash the agent loop.
        }
    }
}
