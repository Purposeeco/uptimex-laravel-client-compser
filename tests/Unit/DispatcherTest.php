<?php

use Uptimex\Client\Delivery\DirectDispatcher;
use Uptimex\Client\Delivery\NullDispatcher;
use Uptimex\Client\Tests\Doubles\FakeTransport;

it('DirectDispatcher sends a batch through the transport', function () {
    $transport = new FakeTransport;

    $accepted = (new DirectDispatcher($transport))->dispatch(telemetryBatch());

    expect($accepted)->toBeTrue()
        ->and($transport->sent)->toHaveCount(1);
});

it('DirectDispatcher reports failure when the transport rejects the batch', function () {
    $accepted = (new DirectDispatcher((new FakeTransport)->fail()))->dispatch(telemetryBatch());

    expect($accepted)->toBeFalse();
});

it('DirectDispatcher skips an empty batch without sending', function () {
    $transport = new FakeTransport;

    $accepted = (new DirectDispatcher($transport))->dispatch(telemetryBatch(events: []));

    expect($accepted)->toBeTrue()
        ->and($transport->sendCalls)->toBe(0);
});

it('NullDispatcher accepts a batch and does nothing', function () {
    expect((new NullDispatcher)->dispatch(telemetryBatch()))->toBeTrue();
});
