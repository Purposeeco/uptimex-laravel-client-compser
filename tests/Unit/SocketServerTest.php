<?php

use Uptimex\Client\Agent\SocketServer;

it('binds and accepts a connecting client', function () {
    $server = new SocketServer('127.0.0.1:0');
    $server->listen();
    $address = stream_socket_get_name($server->serverStream(), false);

    expect($server->connectionCount())->toBe(0);

    $client = stream_socket_client('tcp://'.$address);
    $server->acceptPending(0.0);

    expect($server->connectionCount())->toBe(1);

    fclose($client);
    $server->close();
});

it('has an idempotent listen()', function () {
    $server = new SocketServer('127.0.0.1:0');
    $server->listen();
    $server->listen(); // must not throw or rebind

    expect(is_resource($server->serverStream()))->toBeTrue();

    $server->close();
});

it('throws when the address cannot be bound', function () {
    $first = new SocketServer('127.0.0.1:0');
    $first->listen();
    $address = stream_socket_get_name($first->serverStream(), false);

    $threw = false;
    try {
        (new SocketServer($address))->listen(); // port already in use
    } catch (RuntimeException) {
        $threw = true;
    } finally {
        $first->close();
    }

    expect($threw)->toBeTrue();
});

it('drops a connection', function () {
    $server = new SocketServer('127.0.0.1:0');
    $server->listen();
    $address = stream_socket_get_name($server->serverStream(), false);

    $client = stream_socket_client('tcp://'.$address);
    $server->acceptPending(0.0);
    expect($server->connectionCount())->toBe(1);

    $server->drop(array_values($server->connections())[0]);
    expect($server->connectionCount())->toBe(0);

    fclose($client);
    $server->close();
});
