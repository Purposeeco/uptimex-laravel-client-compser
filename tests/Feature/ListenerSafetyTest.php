<?php

use Illuminate\Cache\Events\CacheMissed;
use Uptimex\Client\Listeners\CacheListener;

/**
 * The SDK's hard guarantee: a failure inside any UptimeX event listener —
 * a bug, a version skew, anything — must never throw into the host
 * application. The service provider registers every listener through
 * `listenSafely()`, which wraps the call in a catch-all.
 */
it('swallows a throwing listener so it can never escape into the host', function () {
    // A listener wired to blow up — simulating a bug, or a version skew
    // where the listener calls a core method the loaded class lacks.
    $this->app->bind(CacheListener::class, fn () => new class
    {
        public function onMissed(object $event): void
        {
            throw new RuntimeException('listener blew up');
        }
    });

    // Firing the event must NOT throw — listenSafely() catches everything,
    // so the host application never sees the SDK's failure.
    event(new CacheMissed('array', 'some-key'));

    expect(true)->toBeTrue(); // reaching this line proves nothing escaped
});
