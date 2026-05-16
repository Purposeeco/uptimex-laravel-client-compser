<?php

namespace Uptimex\Client\Exceptions;

use Throwable;
use Uptimex\Client\Uptimex;

/**
 * Builds the payload sent for a thrown exception:
 *   - fingerprint = sha1(class | code | file | line)
 *   - stack: top N frames (class, function, file, line)
 *   - source: optional ±N lines of source code around the throw site
 *
 * Same-fingerprint exceptions are grouped server-side; the message is captured
 * per-occurrence but not part of the fingerprint, so a typo in the message
 * doesn't fork the group.
 */
final class ExceptionCapture
{
    private const STACK_FRAME_LIMIT = 50;

    private const SOURCE_CONTEXT_LINES = 5;

    public function __construct(private readonly Uptimex $uptimex) {}

    public function capture(Throwable $e): void
    {
        if (! $this->uptimex->isRecording()) {
            return;
        }

        $payload = [
            'class' => $e::class,
            'message' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'code' => (string) $e->getCode(),
            'fingerprint' => self::fingerprint($e),
            'stack' => $this->captureStack($e),
        ];

        if (config('uptimex.capture_exception_source_code', true)) {
            $payload['source'] = $this->captureSource($e);
        }

        $this->uptimex->record('exception', $payload);
    }

    public static function fingerprint(Throwable $e): string
    {
        return sha1(implode('|', [
            $e::class,
            (string) $e->getCode(),
            $e->getFile(),
            (string) $e->getLine(),
        ]));
    }

    /**
     * @return list<array{file:?string, line:?int, function:?string, class:?string}>
     */
    private function captureStack(Throwable $e): array
    {
        $frames = [];

        foreach (array_slice($e->getTrace(), 0, self::STACK_FRAME_LIMIT) as $frame) {
            $frames[] = [
                'file' => $frame['file'] ?? null,
                'line' => isset($frame['line']) ? (int) $frame['line'] : null,
                'function' => $frame['function'] ?? null,
                'class' => $frame['class'] ?? null,
            ];
        }

        return $frames;
    }

    /**
     * @return array{file:string, line:int, lines:array<int,string>}|null
     */
    private function captureSource(Throwable $e): ?array
    {
        $file = $e->getFile();
        $line = $e->getLine();

        if (! is_file($file) || ! is_readable($file)) {
            return null;
        }

        $contents = @file($file, FILE_IGNORE_NEW_LINES);
        if ($contents === false) {
            return null;
        }

        $start = max(0, $line - 1 - self::SOURCE_CONTEXT_LINES);
        $end = min(count($contents) - 1, $line - 1 + self::SOURCE_CONTEXT_LINES);

        $lines = [];
        for ($i = $start; $i <= $end; $i++) {
            $lines[$i + 1] = $contents[$i];
        }

        return [
            'file' => $file,
            'line' => $line,
            'lines' => $lines,
        ];
    }
}
