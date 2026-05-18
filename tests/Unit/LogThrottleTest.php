<?php

use Illuminate\Support\Facades\Log;
use Uptimex\Client\Support\LogThrottle;

it('logs the first occurrence of a key immediately', function () {
    Log::spy();

    LogThrottle::warn('uptimex.test.flood', 'something failed', ['code' => 28]);

    Log::shouldHaveReceived('warning')->once()->with('something failed', ['code' => 28]);
});

it('suppresses repeats within the cooldown window', function () {
    Log::spy();

    LogThrottle::warn('k', 'msg', [], 300, now: 1_000.0);
    LogThrottle::warn('k', 'msg', [], 300, now: 1_100.0);
    LogThrottle::warn('k', 'msg', [], 300, now: 1_200.0);

    Log::shouldHaveReceived('warning')->once();
});

it('emits one summary carrying the suppressed count after the window elapses', function () {
    Log::spy();

    LogThrottle::warn('k', 'msg', [], 300, now: 1_000.0); // first — logged
    LogThrottle::warn('k', 'msg', [], 300, now: 1_100.0); // suppressed (1)
    LogThrottle::warn('k', 'msg', [], 300, now: 1_200.0); // suppressed (2)
    LogThrottle::warn('k', 'msg', [], 300, now: 1_400.0); // window elapsed — summary

    Log::shouldHaveReceived('warning')->twice();
    Log::shouldHaveReceived('warning')->withArgs(
        fn ($message, $context) => $message === 'msg'
            && ($context['uptimex_throttle']['suppressed'] ?? null) === 2
    )->once();
});

it('throttles distinct keys independently', function () {
    Log::spy();

    LogThrottle::warn('key-a', 'msg a');
    LogThrottle::warn('key-b', 'msg b');

    Log::shouldHaveReceived('warning')->twice();
});

it('restarts the window after emitting a summary', function () {
    Log::spy();

    LogThrottle::warn('k', 'msg', [], 300, now: 1_000.0); // first
    LogThrottle::warn('k', 'msg', [], 300, now: 1_400.0); // summary; window restarts at 1400
    LogThrottle::warn('k', 'msg', [], 300, now: 1_500.0); // suppressed again

    Log::shouldHaveReceived('warning')->twice();
});

it('reset() clears state so the next call logs again', function () {
    Log::spy();

    LogThrottle::warn('k', 'msg', [], 300, now: 1_000.0);
    LogThrottle::warn('k', 'msg', [], 300, now: 1_100.0); // suppressed
    LogThrottle::reset();
    LogThrottle::warn('k', 'msg', [], 300, now: 1_100.0); // logs again — state cleared

    Log::shouldHaveReceived('warning')->twice();
});

it('never throws even if the logger itself fails', function () {
    Log::shouldReceive('warning')->andThrow(new RuntimeException('logger down'));

    LogThrottle::warn('uptimex.transport.network_failed', 'msg', ['x' => 1]);

    expect(true)->toBeTrue(); // reached here → warn() swallowed the logger failure
});
