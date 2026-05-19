<?php

use Illuminate\Support\Facades\Artisan;
use Uptimex\Client\Tests\Doubles\FakeTransport;
use Uptimex\Client\Transport\Transport;

it('sends a real batch through the transport', function () {
    $exit = Artisan::call('uptimex:test');

    expect($exit)->toBe(0)
        ->and($this->transport->sentBatches())->toHaveCount(1)
        ->and($this->transport->sentBatches()[0]['events'][0]['type'])->toBe('request');
});

it('reports failure when the transport rejects the batch', function () {
    $this->app->instance(Transport::class, (new FakeTransport)->fail());

    expect(Artisan::call('uptimex:test'))->toBe(1);
});

it('reports failure when UptimeX is not configured', function () {
    config()->set('uptimex.token', '');

    expect(Artisan::call('uptimex:test'))->toBe(1);
});
