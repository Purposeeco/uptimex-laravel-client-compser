<?php

use Uptimex\Client\Context\ExecutionContext;
use Uptimex\Client\Exceptions\ExceptionCapture;
use Uptimex\Client\Facades\Uptimex;

it('builds a fingerprint from class+code+file+line', function () {
    // Throw both exceptions from the SAME line so file+line+code match.
    $thrower = fn (string $msg) => throw new RuntimeException($msg, 5);

    $a = null;
    $b = null;
    try {
        $thrower('boom');
    } catch (RuntimeException $e) {
        $a = $e;
    }
    try {
        $thrower('different message');
    } catch (RuntimeException $e) {
        $b = $e;
    }

    expect(ExceptionCapture::fingerprint($a))->toBe(ExceptionCapture::fingerprint($b))
        ->and(ExceptionCapture::fingerprint($a))->toMatch('/^[0-9a-f]{40}$/');
});

it('builds different fingerprints for different lines', function () {
    $a = new RuntimeException('a');
    $b = new RuntimeException('b');

    expect(ExceptionCapture::fingerprint($a))->not->toBe(ExceptionCapture::fingerprint($b));
});

it('records an exception event on the active trace', function () {
    Uptimex::startTrace(ExecutionContext::TYPE_REQUEST);

    $capture = $this->app->make(ExceptionCapture::class);
    $capture->capture(new RuntimeException('something went wrong'));

    $events = Uptimex::buffer()?->all() ?? [];
    expect($events)->toHaveCount(1);

    $event = $events[0];
    expect($event['type'])->toBe('exception')
        ->and($event['class'])->toBe('RuntimeException')
        ->and($event['message'])->toBe('something went wrong')
        ->and($event['fingerprint'])->toMatch('/^[0-9a-f]{40}$/')
        ->and($event['stack'])->toBeArray();
});

it('does nothing when no trace is active', function () {
    $capture = $this->app->make(ExceptionCapture::class);
    $capture->capture(new RuntimeException('boom'));

    expect(Uptimex::buffer())->toBeNull();
});

it('does nothing when SDK is disabled', function () {
    config()->set('uptimex.token', '');

    Uptimex::startTrace(ExecutionContext::TYPE_REQUEST);

    $capture = $this->app->make(ExceptionCapture::class);
    $capture->capture(new RuntimeException('boom'));

    expect(Uptimex::buffer()?->count())->toBe(0);
});
