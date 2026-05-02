<?php

namespace Uptimex\Client\Logging;

use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;
use Throwable;
use Uptimex\Client\Uptimex;

/**
 * Monolog handler that mirrors every record matching the configured min level
 * into the active Uptimex trace as a `log` event. Threadsafe — Monolog calls
 * `write()` synchronously per record, and we never block on I/O here.
 *
 * Wired up via `UptimexLogChannel::__invoke()`, which is set as the
 * `via` factory for a custom channel in `config/logging.php`.
 */
final class UptimexLogHandler extends AbstractProcessingHandler
{
    public function __construct(
        private readonly Uptimex $uptimex,
        Level|int|string $level = Level::Debug,
    ) {
        parent::__construct($level, bubble: true);
    }

    protected function write(LogRecord $record): void
    {
        try {
            if (! $this->uptimex->isEnabled() || $this->uptimex->context() === null) {
                return;
            }

            $this->uptimex->record('log', [
                'level' => self::psrLevelFromMonolog($record->level),
                'channel' => $record->channel,
                'message' => $record->message,
                'context' => self::scrubContext($record->context),
                'extras' => $record->extra,
            ]);
        } catch (Throwable) {
            // Never let observability code break the host application.
        }
    }

    private static function psrLevelFromMonolog(Level $level): string
    {
        return match ($level) {
            Level::Debug => 'debug',
            Level::Info => 'info',
            Level::Notice => 'notice',
            Level::Warning => 'warning',
            Level::Error => 'error',
            Level::Critical => 'critical',
            Level::Alert => 'alert',
            Level::Emergency => 'emergency',
        };
    }

    /**
     * Strip ResizableStream / Closure / object handles that won't survive JSON
     * encoding. Anything truly opaque becomes a class-name marker.
     */
    private static function scrubContext(array $context): array
    {
        $scrub = static function (mixed $v) use (&$scrub): mixed {
            if (is_array($v)) {
                return array_map($scrub, $v);
            }
            if (is_scalar($v) || $v === null) {
                return $v;
            }
            if ($v instanceof Throwable) {
                return ['__exception' => $v::class, 'message' => $v->getMessage()];
            }
            if (is_object($v) && method_exists($v, '__toString')) {
                return (string) $v;
            }

            return ['__object' => $v::class ?? gettype($v)];
        };

        return array_map($scrub, $context);
    }
}
