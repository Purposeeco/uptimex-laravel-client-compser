<?php

use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Uptimex\Client\Transport\HttpTransport;

/**
 * REAL network failure tests. These open actual sockets to deliberately
 * broken endpoints (port nobody listens on, .invalid TLDs, hung sockets)
 * and verify the SDK's fail-closed promise: never throws, always returns
 * false, never blocks the host process beyond the configured timeout.
 *
 * Unlike the rest of the Integration suite, these do NOT need a live
 * UptimeX backend — only working loopback networking. They run unconditionally
 * in CI so the timeout/error-swallowing behavior is always exercised.
 */
function makeBrokenTransport(string $url, float $timeout = 0.5, float $connectTimeout = 0.5): HttpTransport
{
    return new HttpTransport(
        http: new Client,
        ingestUrl: $url,
        token: 'utx_test',
        timeout: $timeout,
        connectTimeout: $connectTimeout,
    );
}

function probeBatch(): array
{
    return [
        'events' => [[
            'type' => 'request',
            'trace_id' => (string) Str::orderedUuid(),
            'occurred_at' => now()->toIso8601String(),
            'duration_ms' => 1,
        ]],
    ];
}

it('returns false and does not throw on connection refused', function () {
    Log::spy();

    $transport = makeBrokenTransport('http://127.0.0.1:1');

    $start = microtime(true);
    $result = $transport->send(probeBatch());
    $elapsed = microtime(true) - $start;

    expect($result)->toBeFalse()
        ->and($elapsed)->toBeLessThan(2.0, 'connection-refused should fail fast, not wait for timeout');
});

it('returns false and does not throw on DNS failure', function () {
    Log::spy();

    // RFC 6761 reserves `.invalid` for names that are guaranteed not to resolve.
    $transport = makeBrokenTransport('http://uptimex-sdk-integration-does-not-exist.invalid');

    $result = $transport->send(probeBatch());

    expect($result)->toBeFalse();
});

it('returns false and respects the read timeout when the server hangs', function () {
    Log::spy();

    // Stand up a TCP socket that accepts the connection (kernel auto-accepts
    // into the listen() backlog) but never reads or replies. The Guzzle client
    // connects, sends the request, and waits for a response that never comes.
    // Read timeout fires at $timeout seconds.
    $server = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);

    if ($server === false) {
        test()->markTestSkipped("Could not bind a local TCP socket: {$errstr}");
    }

    try {
        $name = stream_socket_get_name($server, false); // "127.0.0.1:54321"
        $port = (int) substr($name, strrpos($name, ':') + 1);

        $transport = makeBrokenTransport("http://127.0.0.1:{$port}", timeout: 0.3, connectTimeout: 2.0);

        $start = microtime(true);
        $result = $transport->send(probeBatch());
        $elapsed = microtime(true) - $start;

        expect($result)->toBeFalse()
            ->and($elapsed)->toBeGreaterThan(0.2, 'read timeout should kick in around the configured value')
            ->and($elapsed)->toBeLessThan(1.5, 'read timeout should not wait for connect_timeout');
    } finally {
        fclose($server);
    }
});

it('returns null and does not throw when sendDeploy hits a refused connection', function () {
    Log::spy();

    $transport = makeBrokenTransport('http://127.0.0.1:1');

    expect($transport->sendDeploy(['reference' => 'unreachable-probe']))->toBeNull();
});
