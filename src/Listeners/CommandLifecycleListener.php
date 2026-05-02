<?php

namespace Uptimex\Client\Listeners;

use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Symfony\Component\Console\Output\BufferedOutput;
use Throwable;
use Uptimex\Client\Context\ExecutionContext;
use Uptimex\Client\Uptimex;

/**
 * Owns the Artisan command lifecycle: starts a trace on `CommandStarting`,
 * records the `command` event + ends the trace on `CommandFinished`. The SDK's
 * own commands (`uptimex:test`, `uptimex:status`) are skipped so they can
 * manage their own flush.
 */
final class CommandLifecycleListener
{
    /**
     * Skip-list — these commands self-flush and would otherwise drag in their
     * own telemetry as a side-effect.
     */
    private const SKIP_COMMANDS = [
        'uptimex:test',
        'uptimex:status',
    ];

    /**
     * @var array<string, float>
     */
    private array $startedAtByName = [];

    public function __construct(private readonly Uptimex $uptimex) {}

    public function onStarting(CommandStarting $event): void
    {
        if (! $this->uptimex->isEnabled() || in_array($event->command, self::SKIP_COMMANDS, true)) {
            return;
        }

        $name = $event->command ?? 'unknown';

        if ($this->uptimex->context() === null) {
            $this->uptimex->startTrace(ExecutionContext::TYPE_COMMAND, ['name' => $name]);
        }

        $this->startedAtByName[$name] = microtime(true);
    }

    public function onFinished(CommandFinished $event): void
    {
        if (! $this->uptimex->isEnabled()) {
            return;
        }

        try {
            $name = $event->command ?? 'unknown';
            if (in_array($name, self::SKIP_COMMANDS, true)) {
                return;
            }

            $start = $this->startedAtByName[$name] ?? null;
            $durationMs = $start !== null ? (int) round((microtime(true) - $start) * 1000) : null;
            unset($this->startedAtByName[$name]);

            $arguments = method_exists($event->input, '__toString') ? (string) $event->input : null;

            $this->uptimex->record('command', [
                'name' => mb_substr($name, 0, 190),
                'arguments' => $arguments !== null ? ['raw' => mb_substr($arguments, 0, 2000)] : null,
                'exit_code' => (int) $event->exitCode,
                'output_excerpt' => $this->captureOutputExcerpt($event),
                'duration_ms' => $durationMs,
                'status' => $event->exitCode === 0 ? 'success' : 'failed',
            ]);
        } finally {
            if ($this->uptimex->context()?->type === ExecutionContext::TYPE_COMMAND) {
                $this->uptimex->endTrace($event->exitCode === 0 ? 'ok' : 'failed');
            }
        }
    }

    /**
     * Capture the last 4 KB of stdout/stderr if the command was run with a
     * `BufferedOutput` (which artisan tests + many CLI scripts use). Streams
     * that aren't buffered (default Symfony ConsoleOutput) return null.
     */
    private function captureOutputExcerpt(CommandFinished $event): ?string
    {
        try {
            $output = $event->output;
            if ($output instanceof BufferedOutput) {
                $content = (string) $output->fetch();

                return mb_substr($content, max(0, mb_strlen($content) - 4096), 4096);
            }
        } catch (Throwable) {
            // Swallow.
        }

        return null;
    }
}
