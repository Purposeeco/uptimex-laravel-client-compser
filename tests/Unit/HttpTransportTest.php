<?php

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Uptimex\Client\Transport\HttpTransport;

function buildTransport(MockHandler $mock, array &$container = [], string $ingestUrl = 'https://ingest.test'): HttpTransport
{
    $stack = HandlerStack::create($mock);
    $stack->push(Middleware::history($container));

    return new HttpTransport(
        http: new Client(['handler' => $stack]),
        ingestUrl: $ingestUrl,
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

it('returns false on a 301 redirect without following it', function () {
    $container = [];
    // An http:// ingest URL against an HTTPS-only server answers 301.
    // The SDK must NOT follow it — following downgrades POST→GET and the
    // server then answers a baffling 405. One request, redirect not chased.
    $transport = buildTransport(
        new MockHandler([
            new Response(301, ['Location' => 'https://ingest.test/api/ingest/events']),
        ]),
        $container,
    );

    expect($transport->send([
        'events' => [['type' => 'request', 'trace_id' => 'abc', 'occurred_at' => '2026-05-16T00:00:00Z']],
    ]))->toBeFalse()
        ->and($container)->toHaveCount(1, 'the redirect must not be followed — exactly one request');
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

it('returns false on 402 (quota-exceeded) without retrying', function () {
    $container = [];
    $transport = buildTransport(
        new MockHandler([new Response(402, [], '{"error":"quota_exceeded"}')]),
        $container,
    );

    $result = $transport->send([
        'events' => [['type' => 'request', 'trace_id' => 'a1b2c3', 'occurred_at' => '2026-05-04T00:00:00Z']],
    ]);

    expect($result)->toBeFalse()
        ->and($container)->toHaveCount(1, 'SDK must not retry on 402 — that would self-DDoS a quota-exceeded tenant');
});

it('fires exactly one HTTP request on any 4xx response (no retry storm)', function () {
    $container = [];
    // If the SDK ever started retrying, MockHandler would run out after the
    // first response and Guzzle would throw — making this assertion fail
    // for the right reason.
    $transport = buildTransport(
        new MockHandler([new Response(429, [], '{"error":"rate_limited"}')]),
        $container,
    );

    expect($transport->send(['events' => [['type' => 'request', 'trace_id' => 'a1b2c3', 'occurred_at' => '2026-05-04T00:00:00Z']]]))->toBeFalse()
        ->and($container)->toHaveCount(1);
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

it('upgrades an http:// ingest URL to https:// so telemetry never crosses the wire in plaintext', function () {
    $container = [];
    // A customer who sets UPTIMEX_INGEST_URL=http://... must not be able to
    // ship telemetry over plaintext. The SDK upgrades the scheme itself rather
    // than trusting the operator — and rather than leaning on the server's
    // 301, which Guzzle would turn into a POST→GET downgrade.
    $transport = buildTransport(
        new MockHandler([new Response(202, [], '{}')]),
        $container,
        'http://ingest.uptimex.tech',
    );

    $transport->send(['events' => [['type' => 'request', 'trace_id' => 'abc']]]);

    expect($container)->toHaveCount(1);
    $uri = $container[0]['request']->getUri();
    expect($uri->getScheme())->toBe('https')
        ->and($uri->getHost())->toBe('ingest.uptimex.tech')
        ->and($uri->getPath())->toBe('/api/ingest/events');
});

it('leaves http:// alone for genuinely-local dev hosts', function (string $ingestUrl) {
    // localhost / 127.0.0.1 / *.test run without a TLS cert under
    // `artisan serve` or Herd — forcing HTTPS there would break local dev.
    $container = [];
    $transport = buildTransport(
        new MockHandler([new Response(202, [], '{}')]),
        $container,
        $ingestUrl,
    );

    $transport->send(['events' => []]);

    expect($container[0]['request']->getUri()->getScheme())->toBe('http');
})->with([
    'http://localhost',
    'http://localhost:8000',
    'http://127.0.0.1:8000',
    'http://uptimex.test',
]);
