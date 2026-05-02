<?php

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request as PsrRequest;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Events\ConnectionFailed;
use Illuminate\Http\Client\Events\RequestSending;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\Client\Response as ClientResponse;
use Uptimex\Client\Context\ExecutionContext;
use Uptimex\Client\Facades\Uptimex;
use Uptimex\Client\Http\OutgoingRequestMiddleware;

function makeClientRequest(string $method, string $url): ClientRequest
{
    return new ClientRequest(new PsrRequest($method, $url));
}

function makeClientResponse(int $status = 200, string $body = 'ok'): ClientResponse
{
    return new ClientResponse(new PsrResponse($status, [], $body));
}

it('records an outgoing request event on ResponseReceived', function () {
    Uptimex::startTrace(ExecutionContext::TYPE_REQUEST);
    $listener = $this->app->make(OutgoingRequestMiddleware::class);

    $req = makeClientRequest('GET', 'https://api.example.com/users/42');
    $listener->onSending(new RequestSending($req));
    usleep(2000);
    $listener->onReceived(new ResponseReceived($req, makeClientResponse(200, 'data')));

    $events = Uptimex::buffer()?->all() ?? [];
    expect($events)->toHaveCount(1);

    $event = $events[0];
    expect($event['type'])->toBe('outgoing_request')
        ->and($event['host'])->toBe('api.example.com')
        ->and($event['method'])->toBe('GET')
        ->and($event['status'])->toBe(200)
        ->and($event['url'])->toBe('https://api.example.com/users/42')
        ->and($event['duration_ms'])->toBeGreaterThanOrEqual(0);
});

it('strips querystring from url', function () {
    Uptimex::startTrace(ExecutionContext::TYPE_REQUEST);
    $listener = $this->app->make(OutgoingRequestMiddleware::class);

    $req = makeClientRequest('GET', 'https://api.example.com/search?api_key=secret&q=phpunit');
    $listener->onSending(new RequestSending($req));
    $listener->onReceived(new ResponseReceived($req, makeClientResponse()));

    $event = Uptimex::buffer()?->all()[0] ?? null;
    expect($event['url'])->toBe('https://api.example.com/search')
        ->and(str_contains($event['url'], 'api_key'))->toBeFalse();
});

it('records a failed connection without status', function () {
    Uptimex::startTrace(ExecutionContext::TYPE_REQUEST);
    $listener = $this->app->make(OutgoingRequestMiddleware::class);

    $req = makeClientRequest('GET', 'https://api.example.com/down');
    $listener->onSending(new RequestSending($req));

    $exception = new ConnectionException(
        'Connection refused',
        previous: new ConnectException('Connection refused', new PsrRequest('GET', 'https://api.example.com/down')),
    );
    $listener->onConnectionFailed(new ConnectionFailed($req, $exception));

    $event = Uptimex::buffer()?->all()[0] ?? null;
    expect($event['type'])->toBe('outgoing_request')
        ->and($event['status'])->toBeNull();
});
