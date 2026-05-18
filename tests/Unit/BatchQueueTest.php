<?php

use Illuminate\Support\Facades\Log;
use Uptimex\Client\Agent\BatchQueue;

it('starts empty', function () {
    $queue = new BatchQueue(100);

    expect($queue->isEmpty())->toBeTrue()
        ->and($queue->count())->toBe(0)
        ->and($queue->peek(5))->toBe([]);
});

it('keeps batches in FIFO order through peek and drop', function () {
    $queue = new BatchQueue(100);
    $queue->push(telemetryBatch('1'));
    $queue->push(telemetryBatch('2'));
    $queue->push(telemetryBatch('3'));

    expect($queue->count())->toBe(3)
        ->and(array_map(fn ($b) => $b->batchUuid, $queue->peek(2)))->toBe(['1', '2']);

    $queue->drop(2);

    expect($queue->count())->toBe(1)
        ->and($queue->peek(10)[0]->batchUuid)->toBe('3');
});

it('drops the oldest batches when the cap is exceeded', function () {
    $queue = new BatchQueue(3);
    foreach (range(1, 7) as $i) {
        $queue->push(telemetryBatch((string) $i));
    }

    expect($queue->count())->toBe(3)
        ->and($queue->droppedTotal())->toBe(4)
        ->and(array_map(fn ($b) => $b->batchUuid, $queue->peek(10)))->toBe(['5', '6', '7']);
});

it('clamps a non-positive cap to at least one', function () {
    $queue = new BatchQueue(0);
    $queue->push(telemetryBatch('a'));
    $queue->push(telemetryBatch('b'));

    expect($queue->count())->toBe(1)
        ->and($queue->peek(10)[0]->batchUuid)->toBe('b');
});

it('logs a queue overflow only once across repeated overflows', function () {
    Log::spy();

    $queue = new BatchQueue(1);
    $queue->push(telemetryBatch('1'));
    $queue->push(telemetryBatch('2')); // overflow
    $queue->push(telemetryBatch('3')); // overflow — suppressed
    $queue->push(telemetryBatch('4')); // overflow — suppressed

    Log::shouldHaveReceived('warning')->once();
});
