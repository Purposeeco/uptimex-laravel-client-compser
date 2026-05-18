<?php

use Uptimex\Client\Agent\BatchQueue;
use Uptimex\Client\Agent\Shipper;
use Uptimex\Client\Tests\Doubles\FakeClock;
use Uptimex\Client\Tests\Doubles\FakeTransport;
use Uptimex\Client\Transport\NullTransport;

it('ships queued batches and removes them on success', function () {
    $queue = new BatchQueue(100);
    $queue->push(telemetryBatch('1'));
    $queue->push(telemetryBatch('2'));
    $transport = new FakeTransport;

    $report = (new Shipper($transport, new FakeClock))->ship($queue, 10);

    expect($report->sent)->toBe(2)
        ->and($report->failed)->toBeFalse()
        ->and($queue->count())->toBe(0)
        ->and($transport->sent)->toHaveCount(2);
});

it('keeps batches queued when a send fails and arms the backoff', function () {
    $queue = new BatchQueue(100);
    $queue->push(telemetryBatch());
    $shipper = new Shipper((new FakeTransport)->fail(), new FakeClock, retryBaseSeconds: 5);

    $report = $shipper->ship($queue, 10);

    expect($report->sent)->toBe(0)
        ->and($report->failed)->toBeTrue()
        ->and($queue->count())->toBe(1)               // retained for retry
        ->and($shipper->consecutiveFailures())->toBe(1)
        ->and($shipper->readyToShip())->toBeFalse();   // now in backoff
});

it('opens the backoff window again once enough time passes', function () {
    $queue = new BatchQueue(100);
    $queue->push(telemetryBatch());
    $clock = new FakeClock;
    $shipper = new Shipper((new FakeTransport)->fail(), $clock, retryBaseSeconds: 5);

    $shipper->ship($queue, 10);
    expect($shipper->readyToShip())->toBeFalse();

    $clock->advance(5);
    expect($shipper->readyToShip())->toBeTrue();
});

it('does not throw when the transport throws', function () {
    $queue = new BatchQueue(100);
    $queue->push(telemetryBatch());
    $shipper = new Shipper(new FakeTransport(succeeds: false, throws: true), new FakeClock);

    $report = $shipper->ship($queue, 10);

    expect($report->failed)->toBeTrue()
        ->and($queue->count())->toBe(1);
});

it('reports a NullTransport as a no-op', function () {
    expect((new Shipper(new NullTransport, new FakeClock))->transportIsNoop())->toBeTrue()
        ->and((new Shipper(new FakeTransport, new FakeClock))->transportIsNoop())->toBeFalse();
});

it('drains the queue best-effort until empty', function () {
    $queue = new BatchQueue(100);
    foreach (range(1, 5) as $i) {
        $queue->push(telemetryBatch((string) $i));
    }
    $transport = new FakeTransport;
    $shipper = new Shipper($transport, $clock = new FakeClock);

    $drained = $shipper->drain($queue, $clock->monotonic() + 10);

    expect($drained)->toBe(5)
        ->and($queue->isEmpty())->toBeTrue()
        ->and($transport->sent)->toHaveCount(5);
});
