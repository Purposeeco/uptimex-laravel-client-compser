<?php

namespace Uptimex\Client\Support;

/**
 * A {@see Lock} backed by an advisory `flock()` on a lock file.
 *
 * `flock(LOCK_EX | LOCK_NB)` is non-blocking — a worker that loses the
 * race returns instantly and does zero drain work. The kernel releases
 * the lock automatically when the holding process dies, so a crashed
 * drainer can never wedge the spool; no lock TTL is needed.
 *
 * The lock is host-local, which is exactly right: each host has its own
 * spool directory and should drain it independently of other hosts.
 */
final class FilesystemLock implements Lock
{
    public function __construct(private readonly string $directory) {}

    public function tryAcquire(string $name): ?LockHandle
    {
        if (! is_dir($this->directory)) {
            @mkdir($this->directory, 0775, true);
        }

        $lockFile = rtrim($this->directory, DIRECTORY_SEPARATOR)
            .DIRECTORY_SEPARATOR.'.'.$name.'.lock';

        $handle = @fopen($lockFile, 'c');
        if ($handle === false) {
            return null;
        }

        if (! @flock($handle, LOCK_EX | LOCK_NB)) {
            @fclose($handle);

            return null;
        }

        return new LockHandle(static function () use ($handle): void {
            @flock($handle, LOCK_UN);
            @fclose($handle);
        });
    }
}
