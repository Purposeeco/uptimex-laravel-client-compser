<?php

use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Uptimex\Client\Agent\AgentClient;
use Uptimex\Client\Context\ExecutionContext;
use Uptimex\Client\Tests\Doubles\FakeDispatcher;
use Uptimex\Client\Transport\HttpTransport;
use Uptimex\Client\Uptimex;

/**
 * REAL end-to-end ingest tests. These open a socket to a live UptimeX
 * backend and verify the wire protocol: gzip framing, bearer auth,
 * payload shape, and 2xx response from the controller.
 *
 * Skipped unless UPTIMEX_INTEGRATION_INGEST_URL and UPTIMEX_INTEGRATION_TOKEN
 * are set. See tests/Pest.php#uptimexIntegrationBoot.
 */
beforeEach(function () {
    [$this->ingestUrl, $this->token, $this->httpTransport] = uptimexIntegrationBoot();
});

it('accepts a synthetic batch via HttpTransport over the wire', function () {
    $traceId = (string) Str::orderedUuid();
    $batchUuid = (string) Str::uuid();

    $accepted = $this->httpTransport->send([
        'batch_uuid' => $batchUuid,
        'events' => [[
            'type' => 'request',
            'trace_id' => $traceId,
            'occurred_at' => now()->toIso8601String(),
            'duration_ms' => 1,
            'meta' => ['source' => 'pest:integration:transport'],
        ]],
    ]);

    expect($accepted)->toBeTrue(
        "Real ingest at {$this->ingestUrl} rejected the batch ".
        "(batch_uuid={$batchUuid}, trace_id={$traceId})."
    );
});

it('runs the full SDK lifecycle (startTrace + record + endTrace) against a real backend', function () {
    $uptimex = new Uptimex(
        config: app('config'),
        dispatcher: new FakeDispatcher($this->httpTransport),
        agent: app(AgentClient::class),
    );

    $context = $uptimex->startTrace(ExecutionContext::TYPE_REQUEST, [
        'source' => 'pest:integration:full-flow',
        'host' => gethostname() ?: 'unknown',
    ]);

    $uptimex->record('request', [
        'method' => 'GET',
        'route' => '/integration-probe',
        'duration_ms' => 1,
    ]);

    expect($uptimex->endTrace('ok'))->toBeTrue(
        "Full SDK lifecycle failed against {$this->ingestUrl} (trace_id={$context->traceId})."
    );
});

it('returns false when the token is invalid (real 401 from server)', function () {
    $bogus = new HttpTransport(
        http: new Client,
        ingestUrl: $this->ingestUrl,
        token: 'utx_definitely_not_a_real_token_'.Str::random(8),
        timeout: 5.0,
        connectTimeout: 2.0,
    );

    Log::spy();

    expect($bogus->send([
        'events' => [[
            'type' => 'request',
            'trace_id' => (string) Str::orderedUuid(),
            'occurred_at' => now()->toIso8601String(),
            'duration_ms' => 1,
        ]],
    ]))->toBeFalse();
});
