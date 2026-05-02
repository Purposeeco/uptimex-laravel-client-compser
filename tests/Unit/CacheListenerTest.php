<?php

use Illuminate\Cache\Events\CacheHit;
use Illuminate\Cache\Events\CacheMissed;
use Illuminate\Cache\Events\KeyForgotten;
use Illuminate\Cache\Events\KeyWritten;
use Uptimex\Client\Context\ExecutionContext;
use Uptimex\Client\Facades\Uptimex;
use Uptimex\Client\Listeners\CacheListener;

it('records hit/miss/write/delete events', function () {
    Uptimex::startTrace(ExecutionContext::TYPE_REQUEST);
    $listener = $this->app->make(CacheListener::class);

    $listener->onHit(new CacheHit('redis', 'app:user:42', 'value'));
    $listener->onMissed(new CacheMissed('redis', 'app:user:99'));
    $listener->onWritten(new KeyWritten('redis', 'app:user:42', 'value', 60));
    $listener->onForgotten(new KeyForgotten('redis', 'app:user:42'));

    $events = Uptimex::buffer()?->all() ?? [];
    expect($events)->toHaveCount(4);

    $types = array_column($events, 'event_type');
    expect($types)->toBe(['hit', 'miss', 'write', 'delete']);

    expect($events[2]['ttl_seconds'])->toBe(60);
});

it('skips framework keys via the deny-list', function () {
    Uptimex::startTrace(ExecutionContext::TYPE_REQUEST);
    $listener = $this->app->make(CacheListener::class);

    $listener->onHit(new CacheHit('redis', 'pulse:metric', 1));
    $listener->onHit(new CacheHit('redis', 'telescope:entry', 'x'));
    $listener->onHit(new CacheHit('redis', 'horizon-jobs', 'x'));
    $listener->onHit(new CacheHit('redis', 'uptimex_session', 'x'));
    $listener->onHit(new CacheHit('redis', 'app:user:42', 'x'));

    $events = Uptimex::buffer()?->all() ?? [];
    expect($events)->toHaveCount(1)
        ->and($events[0]['key'])->toBe('app:user:42');
});

it('does nothing when no trace is active', function () {
    $listener = $this->app->make(CacheListener::class);
    $listener->onHit(new CacheHit('redis', 'app:user:42', 'x'));

    expect(Uptimex::buffer())->toBeNull();
});
