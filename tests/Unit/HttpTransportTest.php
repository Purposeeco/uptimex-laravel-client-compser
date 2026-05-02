<?php

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Uptimex\Client\Transport\HttpTransport;

function buildTransport(MockHandler $mock, array &$container = []): HttpTransport
{
    $stack = HandlerStack::create($mock);
    $stack->push(Middleware::history($container));

    return new HttpTransport(
        http: new Client(['handler' => $stack]),
        ingestUrl: 'https://ingest.test',
        token: 'utx_test',
        timeout: 0.5,
        connectTimeout: 0.5,
    );
}

it('returns true on a 202 response', function () {
    $transport = buildTransport(new MockHandler([new Response(202, [], '{"batch_id":1}')]));

    $result = $transport->send([
        'events' => [['type' => 'request', 'trace_id' => 'abc']],
    ]);

    expect($result)->toBeTrue();
});

it('returns false on 4xx', function () {
    $transport = buildTransport(new MockHandler([new Response(401, [], '{"error":"unauth"}')]));

    expect($transport->send(['events' => []]))->toBeFalse();
});

it('returns false on 5xx', function () {
    $transport = buildTransport(new MockHandler([new Response(503, [], 'down')]));

    expect($transport->send(['events' => []]))->toBeFalse();
});

it('does not throw on network failure', function () {
    $transport = buildTransport(new MockHandler([
        new ConnectException(
            'Connection refused',
            new Request('POST', 'https://ingest.test'),
        ),
    ]));

    expect($transport->send(['events' => []]))->toBeFalse();
});

it('sends a gzip-encoded JSON body with bearer auth', function () {
    $container = [];
    $transport = buildTransport(new MockHandler([new Response(202, [], '{}')]), $container);

    $transport->send([
        'batch_uuid' => 'abc-123',
        'events' => [['type' => 'request', 'trace_id' => 'xyz']],
    ]);

    expect($container)->toHaveCount(1);

    $request = $container[0]['request'];
    expect($request->getMethod())->toBe('POST')
        ->and($request->getUri()->getPath())->toBe('/api/ingest/events')
        ->and($request->getHeaderLine('Authorization'))->toBe('Bearer utx_test')
        ->and($request->getHeaderLine('Content-Encoding'))->toBe('gzip')
        ->and($request->getHeaderLine('Content-Type'))->toBe('application/json');

    $decoded = gzdecode((string) $request->getBody());
    expect(json_decode($decoded, true)['events'][0]['type'])->toBe('request');
});
