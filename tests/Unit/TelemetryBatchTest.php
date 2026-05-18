<?php

use Uptimex\Client\Delivery\TelemetryBatch;

it('round-trips through toArray and fromArray', function () {
    $batch = new TelemetryBatch(
        batchUuid: 'b-1',
        sdkVersion: '0.1.0',
        host: 'web-01',
        sampleRate: 0.5,
        context: ['tenant' => 7],
        events: [['type' => 'request', 'trace_id' => 't-1']],
    );

    $restored = TelemetryBatch::fromArray($batch->toArray());

    expect($restored->batchUuid)->toBe('b-1')
        ->and($restored->sdkVersion)->toBe('0.1.0')
        ->and($restored->host)->toBe('web-01')
        ->and($restored->sampleRate)->toBe(0.5)
        ->and($restored->context)->toBe(['tenant' => 7])
        ->and($restored->events)->toBe([['type' => 'request', 'trace_id' => 't-1']]);
});

it('produces the batch array shape the ingest endpoint expects', function () {
    $batch = new TelemetryBatch('b-1', '0.1.0', null, null, null, []);

    expect($batch->toArray())->toBe([
        'batch_uuid' => 'b-1',
        'sdk_version' => '0.1.0',
        'host' => null,
        'sample_rate' => null,
        'context' => null,
        'events' => [],
    ]);
});

it('reports emptiness and event count', function () {
    expect((new TelemetryBatch('b', 'v', null, null, null, []))->isEmpty())->toBeTrue()
        ->and((new TelemetryBatch('b', 'v', null, null, null, [['type' => 'x']]))->isEmpty())->toBeFalse()
        ->and((new TelemetryBatch('b', 'v', null, null, null, [['a' => 1], ['b' => 2]]))->eventCount())->toBe(2);
});

it('tolerates a partial payload in fromArray', function () {
    $restored = TelemetryBatch::fromArray(['events' => [['type' => 'log']]]);

    expect($restored->batchUuid)->toBe('')
        ->and($restored->host)->toBeNull()
        ->and($restored->sampleRate)->toBeNull()
        ->and($restored->context)->toBeNull()
        ->and($restored->events)->toBe([['type' => 'log']]);
});
