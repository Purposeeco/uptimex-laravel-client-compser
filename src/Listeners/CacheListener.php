<?php

namespace Uptimex\Client\Listeners;

use Illuminate\Cache\Events\CacheEvent;
use Illuminate\Cache\Events\CacheHit;
use Illuminate\Cache\Events\CacheMissed;
use Illuminate\Cache\Events\KeyForgotten;
use Illuminate\Cache\Events\KeyWritten;
use Uptimex\Client\Uptimex;

/**
 * Records every `Illuminate\Cache\Events\*` as an `events_cache` row.
 *
 * A built-in deny-list (regex patterns matching framework / package keys)
 * suppresses noise from Telescope, Pulse, Nova, Vapor, Reverb, plus the
 * SDK's own keys. Phase 7 makes this configurable per environment.
 *
 * Note: Laravel doesn't currently fire a `CacheFailed` event, so this
 * listener handles the four implemented events; `cache.fail` rows are
 * emitted only when a downstream wrapper opts to call `record()` directly.
 */
final class CacheListener
{
    /**
     * Regex patterns matching keys we never capture. Each pattern is matched
     * against the full key including any prefix. Pcre is anchored at the
     * start automatically via `preg_match` semantics.
     */
    private const DENY_PATTERNS = [
        '/^pulse[:_]/i',
        '/^telescope[:_]/i',
        '/^nova[:_]/i',
        '/^vapor[:_]/i',
        '/^reverb[:_]/i',
        '/^uptimex[:_-]/i',
        '/^horizon[:_-]/i',
        '/^laravel[:_]session[:_]/i',
        '/^framework\//i',
    ];

    public function __construct(private readonly Uptimex $uptimex) {}

    public function onHit(CacheHit $event): void
    {
        $this->record($event, 'hit');
    }

    public function onMissed(CacheMissed $event): void
    {
        $this->record($event, 'miss');
    }

    public function onWritten(KeyWritten $event): void
    {
        $this->record($event, 'write', ttl: $this->ttlSecondsFromEvent($event));
    }

    public function onForgotten(KeyForgotten $event): void
    {
        $this->record($event, 'delete');
    }

    private function record(CacheEvent $event, string $type, ?int $ttl = null): void
    {
        if (! $this->uptimex->isEnabled() || $this->uptimex->context() === null) {
            return;
        }

        $key = (string) $event->key;
        if ($this->isDenied($key)) {
            return;
        }

        $this->uptimex->record('cache', [
            'store' => $event->storeName ?? null,
            'key' => mb_substr($key, 0, 255),
            'event_type' => $type,
            'ttl_seconds' => $ttl,
        ]);
    }

    private function isDenied(string $key): bool
    {
        foreach (self::DENY_PATTERNS as $pattern) {
            if (preg_match($pattern, $key) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * `KeyWritten` exposes the TTL via `seconds` on Laravel 9+; older versions
     * expose it via `minutes`. Probe both.
     */
    private function ttlSecondsFromEvent(KeyWritten $event): ?int
    {
        if (property_exists($event, 'seconds') && is_int($event->seconds)) {
            return $event->seconds;
        }
        if (property_exists($event, 'minutes') && is_int($event->minutes)) {
            return $event->minutes * 60;
        }

        return null;
    }
}
