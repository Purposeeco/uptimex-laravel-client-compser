<?php

namespace Uptimex\Client\Support;

/**
 * A non-blocking, single-host mutual-exclusion lock. The spool drainer
 * uses it to guarantee that — across every PHP-FPM worker on a host —
 * at most one drain runs at a time.
 */
interface Lock
{
    /**
     * Attempt to acquire the named lock WITHOUT blocking.
     *
     * Returns a handle to release it, or null if another process already
     * holds it. A caller that gets null must do nothing and return — it
     * must never wait.
     */
    public function tryAcquire(string $name): ?LockHandle;
}
