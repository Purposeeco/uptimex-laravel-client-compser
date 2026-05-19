<?php

use Uptimex\Client\Delivery\BatchDispatcher;
use Uptimex\Client\Delivery\NullDispatcher;
use Uptimex\Client\Delivery\SocketDispatcher;

it('NullDispatcher accepts a batch and does nothing', function () {
    expect((new NullDispatcher)->dispatch(telemetryBatch()))->toBeTrue();
});

it('resolves the agent SocketDispatcher when the SDK is configured', function () {
    app()->forgetInstance(BatchDispatcher::class);

    expect(app(BatchDispatcher::class))->toBeInstanceOf(SocketDispatcher::class);
});

it('resolves a no-op NullDispatcher when the SDK is disabled or token-less', function (string $key, $value) {
    config()->set($key, $value);
    app()->forgetInstance(BatchDispatcher::class);

    expect(app(BatchDispatcher::class))->toBeInstanceOf(NullDispatcher::class);
})->with([
    'disabled' => ['uptimex.enabled', false],
    'no token' => ['uptimex.token', ''],
]);
