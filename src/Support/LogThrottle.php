<?php

namespace Uptimex\Client\Support;

use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Process-level log throttle for repeating SDK warnings.
 *
 * The first occurrence of a keyed warning is logged immediately; further
 * occurrences within the cooldown window are counted and suppressed; the
 * first occurrence after the window emits one summary line carrying the
 * suppressed count, then the window restarts.
 *
 * State lives in a PHP `static` — fileless, dependency-free, and correct for
 * both SDK runtimes: it persists for the whole life of the long-lived
 * `uptimex:agent` process, and across every request a php-fpm worker serves
 * (PHP statics outlive the per-request container rebuild). In `direct`
 * delivery mode dedup is therefore per-worker, not per-host — acceptable: an
 * ingest outage yields at most one line per worker per cooldown instead of
 * one line per failed send.
 *
 * Never throws — it is called from telemetry code that must not break the
 * host application.
 */
final class LogThrottle
{
    /** Default cooldown window, in seconds. */
    public const DEFAULT_COOLDOWN = 300;

    /**
     * Per-key throttle state.
     *
     * @var array<string, array{first: float, suppressed: int}>
     */
    private static array $state = [];

    /**
     * Emit a throttled warning. The first call for a given $key logs
     * immediately; calls within $cooldownSeconds are suppressed and counted;
     * the next call after the window logs a summary carrying that count.
     *
     * $key must be stable (an event/channel name) — never include volatile
     * data such as an exception message, or the throttle never suppresses.
     * Volatile detail belongs in $context.
     *
     * @param  array<string, mixed>  $context
     */
    public static function warn(
        string $key,
        string $message,
        array $context = [],
        int $cooldownSeconds = self::DEFAULT_COOLDOWN,
        ?float $now = null,
    ): void {
        $now ??= microtime(true);

        try {
            $entry = self::$state[$key] ?? null;

            if ($entry === null) {
                // First occurrence — log it, open the cooldown window.
                self::$state[$key] = ['first' => $now, 'suppressed' => 0];
                Log::warning($message, $context);

                return;
            }

            if (($now - $entry['first']) < $cooldownSeconds) {
                // Inside the window — suppress, just count.
                self::$state[$key]['suppressed']++;

                return;
            }

            // Window elapsed — emit one summary line, restart the window.
            $suppressed = $entry['suppressed'];
            self::$state[$key] = ['first' => $now, 'suppressed' => 0];

            Log::warning($message, $context + [
                'uptimex_throttle' => [
                    'suppressed' => $suppressed,
                    'window_seconds' => $cooldownSeconds,
                ],
            ]);
        } catch (Throwable) {
            // Observability code must never break the host application.
        }
    }

    /**
     * Clear all throttle state. Intended for tests — production code has no
     * reason to call this.
     */
    public static function reset(): void
    {
        self::$state = [];
    }
}
