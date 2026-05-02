<?php

namespace Uptimex\Client\Facades;

use Illuminate\Support\Facades\Facade;
use Uptimex\Client\Context\ExecutionContext;

/**
 * @method static bool isEnabled()
 * @method static ?ExecutionContext context()
 * @method static ?float sampleRate()
 * @method static ExecutionContext startTrace(string $type, array $metadata = [])
 * @method static bool endTrace(string $status = 'ok')
 * @method static void sample(float $rate)
 * @method static void record(string $type, array $payload = [])
 * @method static void pause()
 * @method static void resume()
 * @method static bool isPaused()
 * @method static mixed ignore(callable $callback)
 * @method static \Uptimex\Client\Uptimex reject(string $type, \Closure $callback)
 * @method static \Uptimex\Client\Uptimex rejectQueries(\Closure $cb)
 * @method static \Uptimex\Client\Uptimex rejectQueuedJobs(\Closure $cb)
 * @method static \Uptimex\Client\Uptimex rejectMail(\Closure $cb)
 * @method static \Uptimex\Client\Uptimex rejectNotifications(\Closure $cb)
 * @method static \Uptimex\Client\Uptimex rejectCacheKeys(\Closure $cb)
 * @method static \Uptimex\Client\Uptimex rejectOutgoingRequests(\Closure $cb)
 * @method static \Uptimex\Client\Uptimex redact(string $type, \Closure $callback)
 * @method static \Uptimex\Client\Uptimex redactHeaders(\Closure $cb)
 * @method static \Uptimex\Client\Uptimex redactPayload(\Closure $cb)
 * @method static \Uptimex\Client\Uptimex redactMail(\Closure $cb)
 * @method static \Uptimex\Client\Uptimex redactQueries(\Closure $cb)
 * @method static \Uptimex\Client\Uptimex redactCacheKeys(\Closure $cb)
 * @method static \Uptimex\Client\Uptimex redactOutgoingRequests(\Closure $cb)
 * @method static \Uptimex\Client\Uptimex redactLogs(\Closure $cb)
 *
 * @see \Uptimex\Client\Uptimex
 */
class Uptimex extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Uptimex\Client\Uptimex::class;
    }
}
