<?php

use Illuminate\Support\Facades\Log;
use Uptimex\Client\Agent\AgentClient;
use Uptimex\Client\Agent\FrameProtocol;
use Uptimex\Client\Delivery\SocketDispatcher;
use Uptimex\Client\Delivery\TelemetryBatch;

it('hands the batch to a running agent', function () {
    $server = stream_socket_server('tcp://127.0.0.1:0');
    $address = stream_socket_get_name($server, false);

    $dispatcher = new SocketDispatcher(new AgentClient($address, connectTimeoutMs: 250));

    expect($dispatcher->dispatch(telemetryBatch('aaaa1111')))->toBeTrue();

    $connection = stream_socket_accept($server, 1);
    $received = stream_get_contents($connection);
    fclose($connection);
    fclose($server);

    $batch = TelemetryBatch::fromArray(json_decode(FrameProtocol::decode($received), true));
    expect($batch->batchUuid)->toBe('aaaa1111');
});

it('skips an empty batch without touching the agent', function () {
    $dispatcher = new SocketDispatcher(new AgentClient('127.0.0.1:1', connectTimeoutMs: 50));

    expect($dispatcher->dispatch(telemetryBatch(events: [])))->toBeTrue();
});

it('returns false when the agent is not running', function () {
    $probe = stream_socket_server('tcp://127.0.0.1:0');
    $deadAddress = stream_socket_get_name($probe, false);
    fclose($probe);

    $dispatcher = new SocketDispatcher(new AgentClient($deadAddress, connectTimeoutMs: 250));

    expect($dispatcher->dispatch(telemetryBatch()))->toBeFalse();
});

it('never throws when the agent address is broken', function () {
    $dispatcher = new SocketDispatcher(new AgentClient('garbage address', connectTimeoutMs: 50));

    expect($dispatcher->dispatch(telemetryBatch()))->toBeFalse();
});

it('drops the batch silently — no log line — when the agent is down', function () {
    $probe = stream_socket_server('tcp://127.0.0.1:0');
    $deadAddress = stream_socket_get_name($probe, false);
    fclose($probe);

    $dispatcher = new SocketDispatcher(new AgentClient($deadAddress, connectTimeoutMs: 250));

    Log::spy();

    expect($dispatcher->dispatch(telemetryBatch()))->toBeFalse();

    Log::shouldNotHaveReceived('warning');
    Log::shouldNotHaveReceived('error');
});
