<?php

namespace Uptimex\Client\Logging;

use Illuminate\Contracts\Foundation\Application;
use Monolog\Logger;
use Uptimex\Client\Uptimex;

/**
 * Factory that returns a Monolog `Logger` writing through the
 * `UptimexLogHandler`. Used as the `via` config in `config/logging.php`:
 *
 *     'uptimex' => [
 *         'driver' => 'custom',
 *         'via'    => Uptimex\Client\Logging\UptimexLogChannel::class,
 *         'level'  => env('UPTIMEX_LOG_LEVEL', 'debug'),
 *     ],
 */
final class UptimexLogChannel
{
    public function __invoke(array $config): Logger
    {
        /** @var Application $app */
        $app = app();

        $level = $config['level'] ?? config('uptimex.log_level', 'debug');

        $logger = new Logger('uptimex');
        $logger->pushHandler(new UptimexLogHandler($app->make(Uptimex::class), $level));

        return $logger;
    }
}
