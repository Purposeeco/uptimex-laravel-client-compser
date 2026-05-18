<?php

use Uptimex\Client\Agent\FrameException;
use Uptimex\Client\Agent\FrameProtocol;

it('prefixes a payload with its 4-byte big-endian length', function () {
    $frame = FrameProtocol::encode('hello');

    expect(strlen($frame))->toBe(FrameProtocol::HEADER_BYTES + 5)
        ->and(substr($frame, 0, 4))->toBe(pack('N', 5))
        ->and(substr($frame, 4))->toBe('hello');
});

it('round-trips encode then decode', function (string $payload) {
    expect(FrameProtocol::decode(FrameProtocol::encode($payload)))->toBe($payload);
})->with([
    'short' => 'a short payload',
    'empty' => '',
    'json' => '{"events":[{"type":"request","trace_id":"abc"}]}',
    'binary' => "\x00\x01\x02\xff\xfe\x7f",
    'large' => str_repeat('x', 200_000),
]);

it('round-trips a length through encodeLength/decodeLength', function (int $length) {
    $header = FrameProtocol::encodeLength($length);

    expect(strlen($header))->toBe(FrameProtocol::HEADER_BYTES)
        ->and(FrameProtocol::decodeLength($header))->toBe($length);
})->with([0, 1, 255, 256, 65535, 1_000_000, 8 * 1024 * 1024]);

it('rejects a payload larger than MAX_FRAME_BYTES', function () {
    FrameProtocol::encode(str_repeat('x', FrameProtocol::MAX_FRAME_BYTES + 1));
})->throws(FrameException::class);

it('rejects a frame shorter than its 4-byte header', function () {
    FrameProtocol::decode('ab');
})->throws(FrameException::class);

it('rejects a frame whose payload length does not match the header', function () {
    FrameProtocol::decode(pack('N', 100).'short');
})->throws(FrameException::class);
