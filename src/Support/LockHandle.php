<?php

namespace Uptimex\Client\Support;

/**
 * An acquired {@see Lock}. Calling {@see release()} frees it; the call is
 * idempotent so a `finally { $handle->release(); }` is always safe.
 */
final class LockHandle
{
    /** @var (callable(): void)|null */
    private $releaser;

    /**
     * @param  callable(): void  $releaser
     */
    public function __construct(callable $releaser)
    {
        $this->releaser = $releaser;
    }

    public function release(): void
    {
        if ($this->releaser === null) {
            return;
        }

        $releaser = $this->releaser;
        $this->releaser = null;
        $releaser();
    }
}
