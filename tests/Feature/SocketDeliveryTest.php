<?php

use Illuminate\Support\Facades\Log;
use Uptimex\Client\Agent\AgentClient;
use Uptimex\Client\Agent\FrameProtocol;
use Uptimex\Client\Context\ExecutionContext;
use Uptimex\Client\Delivery\BatchDispatcher;
use Uptimex\Client\Delivery\SocketDispatcher;
use Uptimex\Client\Delivery\TelemetryBatch;
use Uptimex\Client\Support\AgentGate;
use Uptimex\Client\Uptimex;

/*
 * End-to-end agent delivery: a finished trace leaves `Uptimex::endTrace()`
 * through the `SocketDispatcher`, crosses the loopback socket as one
 * length-prefixed frame, and is decodable on the other side — no network
 * call and no file on the request path. With no agent listening the SDK
 * starts no trace at all, and any batch already in flight is dropped silently.
 */

it('ships a finished trace to the agent socket as one decodable frame', function () {
    $server = stream_socket_server('tcp://127.0.0.1:0');
    $address = stream_socket_get_name($server, false);
    $agent = new AgentClient($address, connectTimeoutMs: 250);

    $uptimex = new Uptimex(config(), new SocketDispatcher($agent), $agent);

    $uptimex->startTrace(ExecutionContext::TYPE_REQUEST, ['method' => 'GET', 'path' => '/']);
    $uptimex->record('request', ['method' => 'GET', 'path' => '/']);

    expect($uptimex->endTrace())->toBeTrue();

    // Read back what the SDK wrote and decode it into a batch.
    $connection = stream_socket_accept($server, 1);
    $received = stream_get_contents($connection);
    fclose($connection);
    fclose($server);

    $batch = TelemetryBatch::fromArray(json_decode(FrameProtocol::decode($received), true));

    expect($batch->events)->toHaveCount(1)
        ->and($batch->events[0]['type'])->toBe('request')
        ->and($batch->eventCount())->toBe(1);
});

it('does not start a trace when no agent is listening', function () {
    // Bind then immediately release a port to get an address nothing answers on.
    $probe = stream_socket_server('tcp://127.0.0.1:0');
    $deadAddress = stream_socket_get_name($probe, false);
    fclose($probe);

    $agent = new AgentClient($deadAddress, connectTimeoutMs: 250);
    $uptimex = new Uptimex(config(), new SocketDispatcher($agent), $agent);

    AgentGate::reset(); // force a real probe of the dead address

    // The circuit breaker reports the agent down — the SDK is inert, exactly
    // as if it were disabled.
    expect($uptimex->shouldStartTrace())->toBeFalse();
});

it('drops a finished trace silently when the agent is unreachable', function () {
    $probe = stream_socket_server('tcp://127.0.0.1:0');
    $deadAddress = stream_socket_get_name($probe, false);
    fclose($probe);

    $agent = new AgentClient($deadAddress, connectTimeoutMs: 250);
    $uptimex = new Uptimex(config(), new SocketDispatcher($agent), $agent);

    $uptimex->startTrace(ExecutionContext::TYPE_REQUEST);
    $uptimex->record('request', ['method' => 'GET']);

    Log::spy();

    expect($uptimex->endTrace())->toBeFalse(); // dropped — the agent is gone

    Log::shouldNotHaveReceived('warning');
    Log::shouldNotHaveReceived('error');
});

it('resolves the agent SocketDispatcher from the container', function () {
    app()->forgetInstance(BatchDispatcher::class);

    expect(app(BatchDispatcher::class))->toBeInstanceOf(SocketDispatcher::class);
});
