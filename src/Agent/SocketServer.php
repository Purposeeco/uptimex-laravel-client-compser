<?php

namespace Uptimex\Client\Agent;

use RuntimeException;

/**
 * The agent's listening socket: binds a `stream_socket_server` on the
 * loopback address, accepts connections without blocking, and owns the set
 * of live {@see Connection}s.
 */
final class SocketServer
{
    /** @var resource|null */
    private $server = null;

    /** @var array<int, Connection> keyed by stream id */
    private array $connections = [];

    public function __construct(
        private readonly string $address,
        private readonly float $connectionTimeout = 5.0,
    ) {}

    /**
     * Bind and listen. Idempotent — a second call is a no-op.
     *
     * @throws RuntimeException if the address cannot be bound.
     */
    public function listen(): void
    {
        if (is_resource($this->server)) {
            return;
        }

        $errno = 0;
        $errstr = '';
        $context = stream_context_create(['socket' => ['so_reuseaddr' => true]]);

        $server = @stream_socket_server(
            $this->uri(),
            $errno,
            $errstr,
            STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
            $context,
        );

        if (! is_resource($server)) {
            throw new RuntimeException(
                "uptimex: agent cannot bind {$this->address}: {$errstr} ({$errno})"
            );
        }

        @stream_set_blocking($server, false);
        $this->server = $server;
    }

    /**
     * @return resource|null
     */
    public function serverStream()
    {
        return $this->server;
    }

    /**
     * @return list<resource>
     */
    public function connectionStreams(): array
    {
        return array_map(
            static fn (Connection $c) => $c->stream(),
            array_values($this->connections),
        );
    }

    /**
     * @return array<int, Connection>
     */
    public function connections(): array
    {
        return $this->connections;
    }

    /**
     * Accept the whole pending backlog, non-blocking.
     */
    public function acceptPending(float $now): void
    {
        if (! is_resource($this->server)) {
            return;
        }

        while (($client = @stream_socket_accept($this->server, 0)) !== false) {
            $this->connections[(int) $client] = new Connection($client, $now);
        }
    }

    public function drop(Connection $connection): void
    {
        $id = (int) $connection->stream();
        $connection->close();
        unset($this->connections[$id]);
    }

    /**
     * Close any connection that has stalled without completing a frame.
     */
    public function reapStalled(float $now): void
    {
        foreach (array_values($this->connections) as $connection) {
            if ($connection->isStalled($now, $this->connectionTimeout)) {
                $this->drop($connection);
            }
        }
    }

    public function connectionCount(): int
    {
        return count($this->connections);
    }

    public function stopListening(): void
    {
        if (is_resource($this->server)) {
            @fclose($this->server);
        }
        $this->server = null;
    }

    public function close(): void
    {
        foreach ($this->connections as $connection) {
            $connection->close();
        }
        $this->connections = [];
        $this->stopListening();
    }

    private function uri(): string
    {
        return str_contains($this->address, '://') ? $this->address : 'tcp://'.$this->address;
    }
}
