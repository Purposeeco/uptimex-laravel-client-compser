<?php

use Illuminate\Support\Str;

/**
 * Phase 7 wire-level acceptance: confirms the server's validator accepts
 * `sample_rate`, `context`, and `host` / `sdk_version` top-level fields
 * when the SDK populates them. Storage-side verification (does the server
 * actually persist these into trace rows?) belongs in the main app's test
 * suite — from the SDK's vantage point, "the validator accepted the
 * shape" is the right scope.
 */
beforeEach(function () {
    [, , $this->httpTransport] = uptimexIntegrationBoot();
});

it('accepts a batch with a sample_rate between 0 and 1', function () {
    expect($this->httpTransport->send([
        'sample_rate' => 0.25,
        'events' => [[
            'type' => 'request',
            'trace_id' => (string) Str::orderedUuid(),
            'occurred_at' => now()->toIso8601String(),
            'duration_ms' => 1,
        ]],
    ]))->toBeTrue();
});

it('accepts a batch with a context bag (Laravel Context propagation)', function () {
    expect($this->httpTransport->send([
        'context' => [
            'request_id' => (string) Str::uuid(),
            'tenant' => 'acme',
            'feature_flags' => ['new-billing' => true],
        ],
        'events' => [[
            'type' => 'request',
            'trace_id' => (string) Str::orderedUuid(),
            'occurred_at' => now()->toIso8601String(),
            'duration_ms' => 1,
        ]],
    ]))->toBeTrue();
});

it('accepts a batch with sample_rate + context + sdk_version + host together', function () {
    expect($this->httpTransport->send([
        'sample_rate' => 1.0,
        'context' => ['env' => 'integration-test'],
        'sdk_version' => '1.0.0-test',
        'host' => gethostname() ?: 'pest-runner',
        'events' => [[
            'type' => 'request',
            'trace_id' => (string) Str::orderedUuid(),
            'occurred_at' => now()->toIso8601String(),
            'duration_ms' => 1,
        ]],
    ]))->toBeTrue();
});

it('rejects sample_rate outside the 0..1 range (server-side bounds)', function () {
    expect($this->httpTransport->send([
        'sample_rate' => 1.5,
        'events' => [[
            'type' => 'request',
            'trace_id' => (string) Str::orderedUuid(),
            'occurred_at' => now()->toIso8601String(),
            'duration_ms' => 1,
        ]],
    ]))->toBeFalse();
});
