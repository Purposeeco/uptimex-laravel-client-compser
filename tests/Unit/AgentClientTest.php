<?php

use Uptimex\Client\Agent\AgentClient;
use Uptimex\Client\Agent\FrameProtocol;

it('writes a complete frame to a listening agent', function () {
    $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    $address = stream_socket_get_name($server, false);

    $ok = (new AgentClient($address, connectTimeoutMs: 250))->send('{"hello":"world"}');

    expect($ok)->toBeTrue();

    $connection = stream_socket_accept($server, 1);
    $received = stream_get_contents($connection);
    fclose($connection);
    fclose($server);

    expect(FrameProtocol::decode($received))->toBe('{"hello":"world"}');
});

it('returns false when no agent is listening', function () {
    // Grab a port then release it — now guaranteed to have no listener.
    $probe = stream_socket_server('tcp://127.0.0.1:0');
    $deadAddress = stream_socket_get_name($probe, false);
    fclose($probe);

    expect((new AgentClient($deadAddress, connectTimeoutMs: 250))->send('payload'))->toBeFalse();
});

it('never throws on a malformed address', function () {
    expect((new AgentClient('not a valid address', connectTimeoutMs: 50))->send('payload'))->toBeFalse();
});

it('ping reports whether the agent is reachable', function () {
    $server = stream_socket_server('tcp://127.0.0.1:0');
    $liveAddress = stream_socket_get_name($server, false);

    $probe = stream_socket_server('tcp://127.0.0.1:0');
    $deadAddress = stream_socket_get_name($probe, false);
    fclose($probe);

    expect((new AgentClient($liveAddress, connectTimeoutMs: 250))->ping())->toBeTrue()
        ->and((new AgentClient($deadAddress, connectTimeoutMs: 250))->ping())->toBeFalse();

    fclose($server);
});
