<?php

use Uptimex\Client\Agent\AgentClient;
use Uptimex\Client\Agent\FrameProtocol;
use Uptimex\Client\Delivery\DirectDispatcher;
use Uptimex\Client\Delivery\SocketDispatcher;
use Uptimex\Client\Delivery\TelemetryBatch;
use Uptimex\Client\Tests\Doubles\FakeTransport;

it('hands the batch to a running agent and does not fall back', function () {
    $server = stream_socket_server('tcp://127.0.0.1:0');
    $address = stream_socket_get_name($server, false);
    $transport = new FakeTransport;

    $dispatcher = new SocketDispatcher(
        new AgentClient($address, connectTimeoutMs: 250),
        new DirectDispatcher($transport),
    );

    expect($dispatcher->dispatch(telemetryBatch('aaaa1111')))->toBeTrue()
        ->and($transport->sendCalls)->toBe(0); // the agent took it — no fallback

    $connection = stream_socket_accept($server, 1);
    $received = stream_get_contents($connection);
    fclose($connection);
    fclose($server);

    $batch = TelemetryBatch::fromArray(json_decode(FrameProtocol::decode($received), true));
    expect($batch->batchUuid)->toBe('aaaa1111');
});

it('falls back to a direct send when no agent is running', function () {
    $probe = stream_socket_server('tcp://127.0.0.1:0');
    $deadAddress = stream_socket_get_name($probe, false);
    fclose($probe);
    $transport = new FakeTransport;

    $dispatcher = new SocketDispatcher(
        new AgentClient($deadAddress, connectTimeoutMs: 250),
        new DirectDispatcher($transport),
    );

    expect($dispatcher->dispatch(telemetryBatch()))->toBeTrue()
        ->and($transport->sent)->toHaveCount(1); // degraded to direct
});

it('skips an empty batch without touching the agent or the transport', function () {
    $transport = new FakeTransport;

    $dispatcher = new SocketDispatcher(
        new AgentClient('127.0.0.1:1', connectTimeoutMs: 50),
        new DirectDispatcher($transport),
    );

    expect($dispatcher->dispatch(telemetryBatch(events: [])))->toBeTrue()
        ->and($transport->sendCalls)->toBe(0);
});

it('never throws and still delivers when the agent address is broken', function () {
    $transport = new FakeTransport;

    $dispatcher = new SocketDispatcher(
        new AgentClient('garbage address', connectTimeoutMs: 50),
        new DirectDispatcher($transport),
    );

    expect($dispatcher->dispatch(telemetryBatch()))->toBeTrue()
        ->and($transport->sent)->toHaveCount(1);
});

it('with no fallback, drops the batch when the agent is down (strict agent-only)', function () {
    $probe = stream_socket_server('tcp://127.0.0.1:0');
    $deadAddress = stream_socket_get_name($probe, false);
    fclose($probe);

    $dispatcher = new SocketDispatcher(
        new AgentClient($deadAddress, connectTimeoutMs: 250),
        fallback: null,
    );

    // UPTIMEX_AGENT_FALLBACK=false → no direct send; the batch is dropped.
    expect($dispatcher->dispatch(telemetryBatch()))->toBeFalse();
});
