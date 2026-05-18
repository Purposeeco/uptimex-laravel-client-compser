<?php

use Uptimex\Client\Agent\Connection;
use Uptimex\Client\Agent\FrameProtocol;

/**
 * @return array{0: resource, 1: resource} a connected [writer, reader] pair
 */
function connectionSocketPair(): array
{
    $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);

    return [$pair[0], $pair[1]];
}

it('assembles a frame delivered in a single chunk', function () {
    [$writer, $reader] = connectionSocketPair();
    fwrite($writer, FrameProtocol::encode('{"batch_uuid":"x"}'));

    $connection = new Connection($reader, 0.0);
    $connection->pump();

    expect($connection->frame())->toBe('{"batch_uuid":"x"}')
        ->and($connection->isDone())->toBeTrue()
        ->and($connection->closeReason())->toBe('frame_complete');

    fclose($writer);
});

it('assembles a frame delivered across several pumps', function () {
    [$writer, $reader] = connectionSocketPair();
    $frame = FrameProtocol::encode('hello agent');
    $connection = new Connection($reader, 0.0);

    fwrite($writer, substr($frame, 0, 2));   // partial header
    $connection->pump();
    expect($connection->frame())->toBeNull();

    fwrite($writer, substr($frame, 2, 5));   // rest of header + partial payload
    $connection->pump();
    expect($connection->frame())->toBeNull();

    fwrite($writer, substr($frame, 7));      // rest of payload
    $connection->pump();

    expect($connection->frame())->toBe('hello agent')
        ->and($connection->isDone())->toBeTrue();

    fclose($writer);
});

it('marks the connection done when the client disconnects mid-frame', function () {
    [$writer, $reader] = connectionSocketPair();
    fwrite($writer, substr(FrameProtocol::encode('incomplete'), 0, 6));
    fclose($writer); // client gone before the frame is complete

    $connection = new Connection($reader, 0.0);
    for ($i = 0; $i < 100 && $connection->pump() && ! $connection->isDone(); $i++) {
        // pump until EOF resolves the connection
    }

    expect($connection->frame())->toBeNull()
        ->and($connection->isDone())->toBeTrue()
        ->and($connection->closeReason())->toBe('client_disconnected');
});

it('rejects an oversized frame length without allocating', function () {
    [$writer, $reader] = connectionSocketPair();
    fwrite($writer, pack('N', FrameProtocol::MAX_FRAME_BYTES + 1));

    $connection = new Connection($reader, 0.0);
    $connection->pump();

    expect($connection->isDone())->toBeTrue()
        ->and($connection->closeReason())->toBe('bad_frame');

    fclose($writer);
});

it('reports a stalled connection past the timeout', function () {
    [$writer, $reader] = connectionSocketPair();
    $connection = new Connection($reader, acceptedAt: 100.0);

    expect($connection->isStalled(103.0, 5.0))->toBeFalse()
        ->and($connection->isStalled(106.0, 5.0))->toBeTrue();

    fclose($writer);
    fclose($reader);
});
