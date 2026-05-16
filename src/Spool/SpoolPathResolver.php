<?php

namespace Uptimex\Client\Spool;

/**
 * Resolves the spool directory layout. One place for all path
 * conventions, shared by {@see FilesystemSpool} and the drain lock.
 */
final class SpoolPathResolver
{
    public function __construct(private readonly string $baseDir) {}

    /**
     * The directory pending batch files live in. Created on first access.
     */
    public function spoolDir(): string
    {
        return $this->ensureDir($this->baseDir);
    }

    /**
     * Where unparseable / corrupt files are quarantined for debugging.
     */
    public function corruptDir(): string
    {
        return $this->ensureDir($this->baseDir.DIRECTORY_SEPARATOR.'corrupt');
    }

    /**
     * Whether the spool base directory exists (or can be created) and is
     * writable. The spooling dispatcher probes this to decide whether to
     * fall back to a direct send.
     */
    public function isWritable(): bool
    {
        if (! is_dir($this->baseDir)) {
            @mkdir($this->baseDir, 0775, true);
        }

        return is_dir($this->baseDir) && is_writable($this->baseDir);
    }

    private function ensureDir(string $dir): string
    {
        if (! is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        return $dir;
    }
}
