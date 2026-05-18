<?php

namespace Uptimex\Client\Agent;

/**
 * The length-prefixed wire frame shared by the SDK (write side, via
 * {@see AgentClient}) and the `uptimex:agent` daemon (read side): a 4-byte
 * big-endian unsigned length, followed by exactly that many bytes of
 * payload (a JSON-encoded telemetry batch).
 *
 * This class owns only the stateless format primitives. Streaming frame
 * assembly across partial socket reads is {@see Connection}'s job — keeping
 * the format in one place means both sides agree byte-for-byte.
 */
final class FrameProtocol
{
    /** Bytes in the length prefix. */
    public const HEADER_BYTES = 4;

    /**
     * Hard ceiling on one frame's payload. A header claiming more than this
     * is rejected as malformed, so a garbage length can never make the agent
     * attempt a wild allocation.
     */
    public const MAX_FRAME_BYTES = 8 * 1024 * 1024; // 8 MiB

    /**
     * Wrap a payload in a length-prefixed frame.
     *
     * @throws FrameException if the payload exceeds MAX_FRAME_BYTES.
     */
    public static function encode(string $payload): string
    {
        $length = strlen($payload);

        if ($length > self::MAX_FRAME_BYTES) {
            throw new FrameException(
                'uptimex: frame payload of '.$length.' bytes exceeds the '
                .self::MAX_FRAME_BYTES.'-byte limit'
            );
        }

        return self::encodeLength($length).$payload;
    }

    /**
     * Decode a complete in-memory frame back to its payload — the inverse of
     * {@see encode()}, for non-streaming callers and tests.
     *
     * @throws FrameException if the buffer is malformed.
     */
    public static function decode(string $frame): string
    {
        if (strlen($frame) < self::HEADER_BYTES) {
            throw new FrameException('uptimex: frame shorter than its 4-byte header');
        }

        $length = self::decodeLength(substr($frame, 0, self::HEADER_BYTES));
        $payload = substr($frame, self::HEADER_BYTES);

        if (strlen($payload) !== $length) {
            throw new FrameException('uptimex: frame payload length does not match its header');
        }

        return $payload;
    }

    /**
     * Encode a length as 4 big-endian bytes.
     */
    public static function encodeLength(int $length): string
    {
        return pack('N', $length);
    }

    /**
     * Decode a 4-byte big-endian header to its unsigned length.
     */
    public static function decodeLength(string $header): int
    {
        $unpacked = unpack('N', $header);

        return is_array($unpacked) ? (int) $unpacked[1] : 0;
    }
}
