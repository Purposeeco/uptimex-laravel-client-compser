<?php

use Uptimex\Client\Agent\Agent;
use Uptimex\Client\Agent\BatchQueue;
use Uptimex\Client\Agent\FrameProtocol;
use Uptimex\Client\Agent\Shipper;
use Uptimex\Client\Agent\SocketServer;
use Uptimex\Client\Tests\Doubles\FakeClock;
use Uptimex\Client\Tests\Doubles\FakeTransport;

/**
 * Connect to the agent address, write one framed payload, close.
 */
function writeFrameToAgent(string $address, string $payload): void
{
    $client = stream_socket_client('tcp://'.$address);
    fwrite($client, FrameProtocol::encode($payload));
    fclose($client);
}

function bootAgent(FakeTransport $transport): array
{
    $server = new SocketServer('127.0.0.1:0');
    $server->listen();
    $address = stream_socket_get_name($server->serverStream(), false);
    $queue = new BatchQueue(100);
    $clock = new FakeClock;
    $agent = new Agent($server, $queue, new Shipper($transport, $clock), $clock);

    return [$agent, $server, $queue, $address];
}

it('accepts a framed batch on the socket and queues it', function () {
    // A failing transport keeps the batch in the queue so it can be inspected
    // — a succeeding transport would ship it straight back out.
    [$agent, $server, $queue, $address] = bootAgent((new FakeTransport)->fail());

    writeFrameToAgent($address, json_encode(telemetryBatch('zzz')->toArray()));

    for ($i = 0; $i < 5 && $queue->count() === 0; $i++) {
        $agent->tick();
    }

    expect($queue->count())->toBe(1)
        ->and($queue->peek(1)[0]->batchUuid)->toBe('zzz');

    $server->close();
});

it('discards a malformed frame without queueing or crashing', function () {
    [$agent, $server, $queue, $address] = bootAgent($transport = new FakeTransport);

    writeFrameToAgent($address, 'this is not json {');

    for ($i = 0; $i < 3; $i++) {
        $agent->tick();
    }

    expect($queue->count())->toBe(0); // malformed → dropped, agent still alive

    $server->close();
});

it('ships a queued batch as it ticks', function () {
    [$agent, $server, $queue, $address] = bootAgent($transport = new FakeTransport);

    writeFrameToAgent($address, json_encode(telemetryBatch('shipme')->toArray()));

    for ($i = 0; $i < 8 && $transport->sent === []; $i++) {
        $agent->tick();
    }

    expect($transport->sent)->toHaveCount(1)
        ->and($queue->isEmpty())->toBeTrue();

    $server->close();
});

it('runOnce reads a pending batch, ships it, and stops', function () {
    [$agent, , , $address] = bootAgent($transport = new FakeTransport);

    writeFrameToAgent($address, json_encode(telemetryBatch('once')->toArray()));

    $exit = $agent->runOnce();

    expect($exit)->toBe(0)
        ->and($transport->sent)->toHaveCount(1);
});
