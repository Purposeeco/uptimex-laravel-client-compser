<?php

namespace Uptimex\Client\Agent;

/**
 * One accepted client connection on the agent socket.
 *
 * Holds the read buffer and a two-state machine — reading the 4-byte length
 * header, then reading that many payload bytes — so a frame arriving in
 * pieces across several event-loop ticks is assembled correctly. The SDK
 * sends exactly one frame per connection; once a frame is complete (or the
 * connection ends / misbehaves) the connection is "done" and the loop
 * closes it.
 */
final class Connection
{
    private const READ_CHUNK = 65536;

    /** @var resource */
    private $stream;

    public readonly float $acceptedAt;

    private string $buffer = '';

    /** Payload length once the header has been read; null while still reading it. */
    private ?int $expectedLength = null;

    private bool $done = false;

    private ?string $closeReason = null;

    private ?string $frame = null;

    /**
     * @param  resource  $stream
     */
    public function __construct($stream, float $acceptedAt)
    {
        $this->stream = $stream;
        $this->acceptedAt = $acceptedAt;
        @stream_set_blocking($stream, false);
    }

    /**
     * @return resource
     */
    public function stream()
    {
        return $this->stream;
    }

    /**
     * Read whatever bytes are currently available and assemble the frame.
     * Returns false on EOF / error (the connection is then done).
     */
    public function pump(): bool
    {
        $chunk = @fread($this->stream, self::READ_CHUNK);

        if ($chunk === false) {
            $this->finish('client_disconnected');

            return false;
        }

        if ($chunk === '') {
            if (feof($this->stream)) {
                $this->finish('client_disconnected');

                return false;
            }

            return true; // nothing available yet — still open
        }

        $this->buffer .= $chunk;
        $this->assemble();

        return true;
    }

    /**
     * The decoded frame payload once a complete frame has been read, else null.
     */
    public function frame(): ?string
    {
        return $this->frame;
    }

    public function isDone(): bool
    {
        return $this->done;
    }

    public function closeReason(): ?string
    {
        return $this->closeReason;
    }

    public function isStalled(float $now, float $timeout): bool
    {
        return ! $this->done && ($now - $this->acceptedAt) >= $timeout;
    }

    public function close(): void
    {
        if (is_resource($this->stream)) {
            @fclose($this->stream);
        }
    }

    private function assemble(): void
    {
        if ($this->expectedLength === null) {
            if (strlen($this->buffer) < FrameProtocol::HEADER_BYTES) {
                return; // header not complete yet
            }

            $length = FrameProtocol::decodeLength(substr($this->buffer, 0, FrameProtocol::HEADER_BYTES));
            if ($length <= 0 || $length > FrameProtocol::MAX_FRAME_BYTES) {
                $this->finish('bad_frame');

                return;
            }

            $this->expectedLength = $length;
            $this->buffer = substr($this->buffer, FrameProtocol::HEADER_BYTES);
        }

        if (strlen($this->buffer) >= $this->expectedLength) {
            $this->frame = substr($this->buffer, 0, $this->expectedLength);
            $this->finish('frame_complete');
        }
    }

    private function finish(string $reason): void
    {
        $this->done = true;
        $this->closeReason = $reason;
    }
}
