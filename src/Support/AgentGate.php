<?php

namespace Uptimex\Client\Support;

use Throwable;

/**
 * Process-level circuit breaker for the `uptimex:agent` daemon.
 *
 * The SDK delivers telemetry only through the agent. When the agent is not
 * running the SDK must behave exactly as if it were disabled — no traces, no
 * buffering, no log noise. This gate makes that cheap: it probes the agent at
 * most once per recheck window and caches the verdict, so the hot path is a
 * single boolean read, not a socket connect per request.
 *
 * State lives in a PHP `static` — fileless, dependency-free, and correct for
 * php-fpm: a static outlives the per-request container rebuild, so the verdict
 * is cached per worker across every request it serves. The verdict expires
 * after the recheck window, so a downed agent is re-probed and capture resumes
 * on its own once the agent is back — and, equally, a stopped agent is noticed
 * within one window.
 *
 * Never throws — it gates telemetry code that must not break the host app.
 */
final class AgentGate
{
    /**
     * The cached health verdict, or null before the first probe.
     *
     * @var array{healthy: bool, checked_at: float}|null
     */
    private static ?array $verdict = null;

    /**
     * Whether the agent is currently reachable. `$probe` is a `callable(): bool`
     * that performs the real reachability check (an `AgentClient::ping()`). It
     * is invoked at most once per `$recheckSeconds`; every other call returns
     * the cached verdict without touching the socket.
     *
     * @param  callable(): bool  $probe
     */
    public static function isAgentUp(callable $probe, int $recheckSeconds, ?float $now = null): bool
    {
        $now ??= microtime(true);

        try {
            $verdict = self::$verdict;

            if ($verdict === null || ($now - $verdict['checked_at']) >= $recheckSeconds) {
                $healthy = (bool) $probe();
                self::$verdict = ['healthy' => $healthy, 'checked_at' => $now];

                return $healthy;
            }

            return $verdict['healthy'];
        } catch (Throwable) {
            // Probing must never break the host application. Fall back to the
            // last known verdict; assume down if we have never probed.
            return self::$verdict['healthy'] ?? false;
        }
    }

    /**
     * Force a verdict without probing. Intended for tests.
     */
    public static function seed(bool $healthy, ?float $now = null): void
    {
        self::$verdict = ['healthy' => $healthy, 'checked_at' => $now ?? microtime(true)];
    }

    /**
     * Clear the cached verdict. Intended for tests — production code has no
     * reason to call this.
     */
    public static function reset(): void
    {
        self::$verdict = null;
    }
}
