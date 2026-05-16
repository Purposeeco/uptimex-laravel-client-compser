<?php

namespace Uptimex\Client\Spool;

use DateTimeImmutable;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;
use Uptimex\Client\Support\Clock;

/**
 * A {@see Spool} that stores one batch per JSON file in a local directory.
 *
 * Durability rules:
 *  - Writes are atomic: a temp file in the same directory is renamed into
 *    place, so a concurrent drainer never reads a half-written file.
 *  - Retry state (attempt count + next-eligible time) is encoded in the
 *    filename, so a failed send is rescheduled with a single atomic
 *    rename — there is no sidecar file to keep consistent.
 *  - A file is removed ONLY after its delivery is confirmed.
 *  - The directory is self-bounding: when the configured cap is hit the
 *    oldest files are dropped and the loss is logged loudly.
 *
 * Filename: {eligibleAt}-{createdAt}-{attempts}-{uuidHex}.json — all the
 * metadata the drainer needs without opening the file. Temp files have no
 * `.json` extension, so the `*.json` glob never matches an in-flight write.
 */
final class FilesystemSpool implements Spool
{
    private const FILENAME = '/^(\d+)-(\d+)-(\d+)-([A-Za-z0-9]+)\.json$/';

    public function __construct(
        private readonly SpoolPathResolver $paths,
        private readonly Clock $clock,
        private readonly int $maxFiles = 10000,
        private readonly int $maxBytes = 524288000,
        private readonly int $retryBaseSeconds = 10,
        private readonly int $retryMaxSeconds = 3600,
    ) {}

    public function write(SpooledBatch $batch): string
    {
        $dir = $this->paths->spoolDir();
        $id = $this->entryId($batch);

        $payload = json_encode($batch->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        $this->enforceCap($dir, strlen($payload));

        $now = $this->clock->now()->getTimestamp();
        $final = $dir.DIRECTORY_SEPARATOR."{$now}-{$now}-0-{$id}.json";

        $this->atomicWrite($dir, $final, $payload);

        return $id;
    }

    public function pending(int $limit): array
    {
        $dir = $this->paths->spoolDir();
        $files = glob($dir.DIRECTORY_SEPARATOR.'*.json') ?: [];
        $now = $this->clock->now()->getTimestamp();

        $candidates = [];
        foreach ($files as $path) {
            $meta = $this->parseName(basename($path));
            if ($meta === null) {
                $this->quarantine($path);

                continue;
            }
            if ($meta['eligible_at'] > $now) {
                continue; // still in backoff
            }
            $candidates[] = ['path' => $path] + $meta;
        }

        // Oldest batch first — FIFO, so a backlog drains in age order.
        usort($candidates, static fn (array $a, array $b): int => $a['created_at'] <=> $b['created_at']);

        $entries = [];
        foreach (array_slice($candidates, 0, max(0, $limit)) as $candidate) {
            $entry = $this->hydrate($candidate);
            if ($entry !== null) {
                $entries[] = $entry;
            }
        }

        return $entries;
    }

    public function delete(string $id): void
    {
        foreach ($this->filesFor($id) as $path) {
            @unlink($path);
        }
    }

    public function markFailed(SpoolEntry $entry): void
    {
        $current = $this->filesFor($entry->id)[0] ?? null;
        if ($current === null) {
            return; // already gone — nothing to reschedule
        }

        $attempts = $entry->attempts + 1;
        $eligibleAt = $this->clock->now()->getTimestamp() + $this->backoffSeconds($attempts);
        $created = $entry->createdAt->getTimestamp();

        $renamed = dirname($current).DIRECTORY_SEPARATOR
            ."{$eligibleAt}-{$created}-{$attempts}-{$entry->id}.json";

        @rename($current, $renamed);
    }

    public function size(): int
    {
        return count(glob($this->paths->spoolDir().DIRECTORY_SEPARATOR.'*.json') ?: []);
    }

    // ---- internals -------------------------------------------------------

    private function entryId(SpooledBatch $batch): string
    {
        $id = preg_replace('/[^A-Za-z0-9]/', '', $batch->batchUuid) ?? '';

        return $id !== '' ? $id : bin2hex(random_bytes(16));
    }

    /**
     * @return array{eligible_at: int, created_at: int, attempts: int, id: string}|null
     */
    private function parseName(string $name): ?array
    {
        if (! preg_match(self::FILENAME, $name, $m)) {
            return null;
        }

        return [
            'eligible_at' => (int) $m[1],
            'created_at' => (int) $m[2],
            'attempts' => (int) $m[3],
            'id' => $m[4],
        ];
    }

    /**
     * @param  array{path: string, eligible_at: int, created_at: int, attempts: int, id: string}  $candidate
     */
    private function hydrate(array $candidate): ?SpoolEntry
    {
        $raw = @file_get_contents($candidate['path']);
        if ($raw === false) {
            return null; // vanished between glob and read — skip
        }

        try {
            $decoded = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            $this->quarantine($candidate['path']);

            return null;
        }

        if (! is_array($decoded)) {
            $this->quarantine($candidate['path']);

            return null;
        }

        return new SpoolEntry(
            id: $candidate['id'],
            batch: SpooledBatch::fromArray($decoded),
            attempts: $candidate['attempts'],
            createdAt: (new DateTimeImmutable)->setTimestamp($candidate['created_at']),
            eligibleAt: (new DateTimeImmutable)->setTimestamp($candidate['eligible_at']),
            sizeBytes: strlen($raw),
        );
    }

    /**
     * @return list<string>
     */
    private function filesFor(string $id): array
    {
        return glob($this->paths->spoolDir().DIRECTORY_SEPARATOR.'*-'.$id.'.json') ?: [];
    }

    private function atomicWrite(string $dir, string $final, string $payload): void
    {
        $tmp = @tempnam($dir, 'uptmp_');
        if ($tmp === false) {
            throw new RuntimeException("uptimex: cannot create a spool temp file in {$dir}");
        }

        if (@file_put_contents($tmp, $payload) === false) {
            @unlink($tmp);

            throw new RuntimeException("uptimex: cannot write a spool file in {$dir}");
        }

        if (! @rename($tmp, $final)) {
            @unlink($tmp);

            throw new RuntimeException("uptimex: cannot publish a spool file in {$dir}");
        }
    }

    private function quarantine(string $path): void
    {
        $dest = $this->paths->corruptDir().DIRECTORY_SEPARATOR.basename($path);

        if (! @rename($path, $dest)) {
            @unlink($path); // cannot quarantine — at least stop re-reading it
        }

        Log::warning('uptimex.spool.corrupt', ['file' => basename($path)]);
    }

    private function backoffSeconds(int $attempts): int
    {
        $exp = $this->retryBaseSeconds * (2 ** max(0, $attempts - 1));
        $raw = (int) min($this->retryMaxSeconds, (int) $exp);

        // 50%-100% jitter so a fleet's retries never synchronise into a herd.
        $half = intdiv($raw, 2);

        return $half + random_int(0, max(0, $half));
    }

    private function enforceCap(string $dir, int $incomingBytes): void
    {
        $files = glob($dir.DIRECTORY_SEPARATOR.'*.json') ?: [];

        $count = count($files);
        $bytes = 0;
        $sized = [];
        foreach ($files as $path) {
            $size = (int) (@filesize($path) ?: 0);
            $bytes += $size;
            $sized[] = ['path' => $path, 'size' => $size, 'created' => $this->createdAt(basename($path))];
        }

        if ($count + 1 <= $this->maxFiles && $bytes + $incomingBytes <= $this->maxBytes) {
            return;
        }

        // Over a cap: drop oldest-first until the incoming file fits.
        usort($sized, static fn (array $a, array $b): int => $a['created'] <=> $b['created']);

        $dropped = 0;
        foreach ($sized as $file) {
            if ($count + 1 <= $this->maxFiles && $bytes + $incomingBytes <= $this->maxBytes) {
                break;
            }
            if (@unlink($file['path'])) {
                $count--;
                $bytes -= $file['size'];
                $dropped++;
            }
        }

        if ($dropped > 0) {
            Log::warning('uptimex.spool.cap_exceeded', [
                'dropped' => $dropped,
                'max_files' => $this->maxFiles,
                'max_bytes' => $this->maxBytes,
            ]);
        }
    }

    private function createdAt(string $name): int
    {
        return $this->parseName($name)['created_at'] ?? 0;
    }
}
