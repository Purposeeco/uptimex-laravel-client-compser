<?php

namespace Uptimex\Client\Agent;

use Throwable;

/**
 * The SDK-side client for the local `uptimex:agent` socket.
 *
 * One batch per connection: connect → write a single length-prefixed frame
 * → close. Both the connect and the write are bounded by a tiny timeout, so
 * a missing or stalled agent costs the request worker microseconds, never a
 * stall. Every failure path returns `false` (the caller — {@see ...\Delivery\
 * SocketDispatcher} — then drops the batch silently). Never throws.
 */
final class AgentClient
{
    public function __construct(
        private readonly string $address,
        private readonly int $connectTimeoutMs = 50,
    ) {}

    /**
     * Write one framed payload to the agent. Returns true only if the whole
     * frame was written.
     */
    public function send(string $payload): bool
    {
        $socket = null;

        try {
            $socket = $this->connect();
            if ($socket === null) {
                return false;
            }

            // Bound the write too — a stalled agent must never hold the worker.
            stream_set_timeout($socket, 0, $this->connectTimeoutMs * 1000);

            return $this->writeAll($socket, FrameProtocol::encode($payload));
        } catch (Throwable) {
            return false;
        } finally {
            if (is_resource($socket)) {
                @fclose($socket);
            }
        }
    }

    /**
     * Whether the agent is reachable right now — connect and immediately
     * close, writing nothing. Used by `uptimex:status`.
     */
    public function ping(): bool
    {
        $socket = $this->connect();
        if ($socket === null) {
            return false;
        }

        @fclose($socket);

        return true;
    }

    /**
     * @return resource|null
     */
    private function connect()
    {
        $errno = 0;
        $errstr = '';

        $socket = @stream_socket_client(
            $this->remoteUri(),
            $errno,
            $errstr,
            max(0.001, $this->connectTimeoutMs / 1000),
            STREAM_CLIENT_CONNECT,
        );

        return is_resource($socket) ? $socket : null;
    }

    /**
     * Normalise the configured address into a `stream_socket_client` URI.
     * `unix:///path` (or any `scheme://…`) passes through; a bare
     * `host:port` becomes `tcp://host:port`.
     */
    private function remoteUri(): string
    {
        return str_contains($this->address, '://')
            ? $this->address
            : 'tcp://'.$this->address;
    }

    /**
     * @param  resource  $socket
     */
    private function writeAll($socket, string $data): bool
    {
        $length = strlen($data);
        $written = 0;

        while ($written < $length) {
            $n = @fwrite($socket, substr($data, $written));
            if ($n === false || $n === 0) {
                return false; // write error or timeout — treat the agent as absent
            }
            $written += $n;
        }

        return true;
    }
}
