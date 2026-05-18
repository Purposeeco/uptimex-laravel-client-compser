<?php

use Uptimex\Client\Agent\AgentClient;
use Uptimex\Client\Agent\FrameProtocol;
use Uptimex\Client\Context\ExecutionContext;
use Uptimex\Client\Delivery\BatchDispatcher;
use Uptimex\Client\Delivery\DirectDispatcher;
use Uptimex\Client\Delivery\SocketDispatcher;
use Uptimex\Client\Delivery\TelemetryBatch;
use Uptimex\Client\Tests\Doubles\FakeTransport;
use Uptimex\Client\Uptimex;

/*
 * End-to-end agent delivery: a finished trace leaves `Uptimex::endTrace()`
 * through the `SocketDispatcher`, crosses the loopback socket as one
 * length-prefixed frame, and is decodable on the other side — no network
 * call and no file on the request path. With no agent listening the
 * dispatcher silently degrades to a direct HTTPS send.
 */

it('ships a finished trace to the agent socket as one decodable frame', function () {
    $server = stream_socket_server('tcp://127.0.0.1:0');
    $address = stream_socket_get_name($server, false);

    $uptimex = new Uptimex(config(), new SocketDispatcher(
        new AgentClient($address, connectTimeoutMs: 250),
        new DirectDispatcher(new FakeTransport),
    ));

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

it('degrades to a direct send when no agent is listening', function () {
    // Bind then immediately release a port to get an address nothing answers on.
    $probe = stream_socket_server('tcp://127.0.0.1:0');
    $deadAddress = stream_socket_get_name($probe, false);
    fclose($probe);

    $transport = new FakeTransport;
    $uptimex = new Uptimex(config(), new SocketDispatcher(
        new AgentClient($deadAddress, connectTimeoutMs: 250),
        new DirectDispatcher($transport),
    ));

    $uptimex->startTrace(ExecutionContext::TYPE_REQUEST);
    $uptimex->record('request', ['method' => 'GET']);

    expect($uptimex->endTrace())->toBeTrue()
        ->and($transport->sent)->toHaveCount(1); // fell back to direct
});

it('resolves the SocketDispatcher from the container when delivery is agent', function () {
    config()->set('uptimex.delivery', 'agent');
    app()->forgetInstance(BatchDispatcher::class);

    expect(app(BatchDispatcher::class))->toBeInstanceOf(SocketDispatcher::class);
});
