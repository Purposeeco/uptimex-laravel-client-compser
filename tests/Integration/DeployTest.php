<?php

use Illuminate\Support\Str;

/**
 * Real-wire tests for the deploy endpoint (POST /api/ingest/deploy).
 * Distinct controller, distinct contract from the events ingest, so it
 * needs its own integration coverage. Verifies:
 *   - 201 on first send (returns deployment_id)
 *   - 200 + idempotent flag on a re-send with the same reference
 *   - null when validation fails (e.g. missing reference)
 */
beforeEach(function () {
    [$this->ingestUrl, , $this->httpTransport] = uptimexIntegrationBoot();
});

it('registers a new deploy and returns a deployment_id', function () {
    $reference = 'integration-'.Str::random(12);

    $response = $this->httpTransport->sendDeploy([
        'reference' => $reference,
        'name' => 'pest:integration:deploy',
        'deployed_at' => now()->toIso8601String(),
        'metadata' => ['ci' => 'pest', 'host' => gethostname() ?: 'unknown'],
    ]);

    expect($response)->toBeArray()
        ->and($response)->toHaveKey('deployment_id');
});

it('returns idempotent=true on a duplicate reference', function () {
    $reference = 'integration-dup-'.Str::random(12);

    $first = $this->httpTransport->sendDeploy([
        'reference' => $reference,
        'name' => 'first send',
    ]);

    expect($first)->toBeArray()->and($first)->toHaveKey('deployment_id');

    $second = $this->httpTransport->sendDeploy([
        'reference' => $reference,
        'name' => 'duplicate send',
    ]);

    expect($second)
        ->toBeArray()
        ->and($second)->toHaveKey('deployment_id')
        ->and($second['deployment_id'])->toBe($first['deployment_id'])
        ->and($second['idempotent'] ?? false)->toBeTrue();
});

it('returns null when the deploy payload is invalid (no reference)', function () {
    $response = $this->httpTransport->sendDeploy([
        'name' => 'missing-reference-on-purpose',
    ]);

    expect($response)->toBeNull();
});
