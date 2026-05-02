<?php

namespace Uptimex\Client;

use GuzzleHttp\Client as GuzzleClient;
use Illuminate\Cache\Events\CacheHit;
use Illuminate\Cache\Events\CacheMissed;
use Illuminate\Cache\Events\KeyForgotten;
use Illuminate\Cache\Events\KeyWritten;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskSkipped;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Foundation\Http\Kernel;
use Illuminate\Http\Client\Events\ConnectionFailed;
use Illuminate\Http\Client\Events\RequestSending;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Notifications\Events\NotificationFailed;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobQueued;
use Illuminate\Queue\Events\JobReleasedAfterException;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Throwable;
use Uptimex\Client\Console\DeployCommand;
use Uptimex\Client\Console\StatusCommand;
use Uptimex\Client\Console\TestCommand;
use Uptimex\Client\Context\ExecutionContext;
use Uptimex\Client\Exceptions\ExceptionCapture;
use Uptimex\Client\Http\CaptureRequestMiddleware;
use Uptimex\Client\Http\OutgoingRequestMiddleware;
use Uptimex\Client\Listeners\CacheListener;
use Uptimex\Client\Listeners\CommandLifecycleListener;
use Uptimex\Client\Listeners\JobLifecycleListener;
use Uptimex\Client\Listeners\MailListener;
use Uptimex\Client\Listeners\NotificationListener;
use Uptimex\Client\Listeners\QueryListener;
use Uptimex\Client\Listeners\ScheduledTaskLifecycleListener;
use Uptimex\Client\Transport\HttpTransport;
use Uptimex\Client\Transport\NullTransport;
use Uptimex\Client\Transport\Transport;

class UptimexServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/uptimex.php', 'uptimex');

        $this->app->singleton(Transport::class, function (Application $app): Transport {
            $config = $app['config'];

            // No token = no real transport. The SDK still works as a no-op.
            if (! $config->get('uptimex.enabled', true) || ! $config->get('uptimex.token')) {
                return new NullTransport;
            }

            return new HttpTransport(
                http: new GuzzleClient(['http_errors' => false]),
                ingestUrl: (string) $config->get('uptimex.ingest_url'),
                token: (string) $config->get('uptimex.token'),
                timeout: (float) $config->get('uptimex.flush_timeout', 0.5),
                connectTimeout: (float) $config->get('uptimex.connect_timeout', 0.5),
            );
        });

        $this->app->singleton(Uptimex::class, function (Application $app): Uptimex {
            return new Uptimex(
                config: $app['config'],
                transport: $app->make(Transport::class),
            );
        });
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/uptimex.php' => config_path('uptimex.php'),
        ], 'uptimex-config');

        if ($this->app->runningInConsole()) {
            $this->commands([
                TestCommand::class,
                StatusCommand::class,
                DeployCommand::class,
            ]);
        }

        $this->registerLifecycleHooks();
        $this->registerHttpMiddleware();
        $this->registerQueryListener();
        $this->registerExceptionReporter();
        $this->registerJobLifecycleListeners();
        $this->registerCacheListeners();
        $this->registerCommandLifecycleListeners();
        $this->registerScheduledTaskLifecycleListeners();
        $this->registerMailListener();
        $this->registerNotificationListener();
        $this->registerOutgoingRequestListeners();
    }

    private function registerCommandLifecycleListeners(): void
    {
        Event::listen(CommandStarting::class, [CommandLifecycleListener::class, 'onStarting']);
        Event::listen(CommandFinished::class, [CommandLifecycleListener::class, 'onFinished']);
    }

    private function registerScheduledTaskLifecycleListeners(): void
    {
        Event::listen(ScheduledTaskStarting::class, [ScheduledTaskLifecycleListener::class, 'onStarting']);
        Event::listen(ScheduledTaskFinished::class, [ScheduledTaskLifecycleListener::class, 'onFinished']);
        Event::listen(ScheduledTaskFailed::class, [ScheduledTaskLifecycleListener::class, 'onFailed']);
        Event::listen(ScheduledTaskSkipped::class, [ScheduledTaskLifecycleListener::class, 'onSkipped']);
    }

    private function registerMailListener(): void
    {
        Event::listen(MessageSent::class, [MailListener::class, 'handle']);
    }

    private function registerNotificationListener(): void
    {
        Event::listen(NotificationSent::class, [NotificationListener::class, 'onSent']);
        Event::listen(NotificationFailed::class, [NotificationListener::class, 'onFailed']);
    }

    private function registerOutgoingRequestListeners(): void
    {
        Event::listen(RequestSending::class, [OutgoingRequestMiddleware::class, 'onSending']);
        Event::listen(ResponseReceived::class, [OutgoingRequestMiddleware::class, 'onReceived']);
        Event::listen(ConnectionFailed::class, [OutgoingRequestMiddleware::class, 'onConnectionFailed']);
    }

    /**
     * Subscribe to Laravel's queue lifecycle events. The same listener
     * instance handles all five so per-job timing state survives across the
     * Processing → Processed pair.
     */
    private function registerJobLifecycleListeners(): void
    {
        Event::listen(JobQueued::class, [JobLifecycleListener::class, 'onQueued']);
        Event::listen(JobProcessing::class, [JobLifecycleListener::class, 'onProcessing']);
        Event::listen(JobProcessed::class, [JobLifecycleListener::class, 'onProcessed']);
        Event::listen(JobReleasedAfterException::class, [JobLifecycleListener::class, 'onReleased']);
        Event::listen(JobFailed::class, [JobLifecycleListener::class, 'onFailed']);
    }

    /**
     * Subscribe to the four built-in cache events. Note: Laravel doesn't
     * currently fire a `CacheFailed` event; cache fail rows are emitted by
     * downstream wrappers calling `Uptimex::record('cache', …)` directly.
     */
    private function registerCacheListeners(): void
    {
        Event::listen(CacheHit::class, [CacheListener::class, 'onHit']);
        Event::listen(CacheMissed::class, [CacheListener::class, 'onMissed']);
        Event::listen(KeyWritten::class, [CacheListener::class, 'onWritten']);
        Event::listen(KeyForgotten::class, [CacheListener::class, 'onForgotten']);
    }

    /**
     * Push the request-capture middleware onto the global HTTP middleware
     * stack so every request is traced. The middleware is a no-op when
     * tenancy is not initialized or the SDK is disabled.
     */
    private function registerHttpMiddleware(): void
    {
        if ($this->app->runningInConsole() && ! $this->app->runningUnitTests()) {
            return;
        }

        try {
            /** @var Kernel $kernel */
            $kernel = $this->app->make(HttpKernel::class);
            if (method_exists($kernel, 'pushMiddleware')) {
                $kernel->pushMiddleware(CaptureRequestMiddleware::class);
            }
        } catch (Throwable) {
            // Swallow — telemetry must never break boot.
        }
    }

    /**
     * Subscribe to `Illuminate\Database\Events\QueryExecuted` so every SQL
     * statement run through Eloquent / the Query Builder is recorded.
     */
    private function registerQueryListener(): void
    {
        Event::listen(QueryExecuted::class, [QueryListener::class, 'handle']);
    }

    /**
     * Register a reportable callback on Laravel's exception handler so any
     * exception reported via `report()` (or thrown out of the request
     * lifecycle) gets captured. The callback returns void so it doesn't
     * short-circuit any other reportable callbacks.
     */
    private function registerExceptionReporter(): void
    {
        try {
            $handler = $this->app->make(ExceptionHandler::class);

            if (method_exists($handler, 'reportable')) {
                $handler->reportable(function (Throwable $e): void {
                    try {
                        $this->app->make(ExceptionCapture::class)->capture($e);
                    } catch (Throwable) {
                        // Swallow.
                    }
                });
            }
        } catch (Throwable) {
            // Swallow.
        }
    }

    /**
     * HTTP-request trace lifecycle. The other two roots (Artisan command,
     * scheduled task) are owned by their dedicated listener classes —
     * `CommandLifecycleListener` and `ScheduledTaskLifecycleListener` —
     * which both manage the trace AND record the corresponding event.
     *
     * The hooks here are defensively wrapped: telemetry must never break
     * the host application's request handling.
     */
    private function registerLifecycleHooks(): void
    {
        Event::listen(function (RequestHandled $event): void {
            try {
                $uptimex = $this->app->make(Uptimex::class);

                if (! $uptimex->isEnabled()) {
                    return;
                }

                if ($uptimex->context() === null) {
                    $uptimex->startTrace(ExecutionContext::TYPE_REQUEST, [
                        'method' => $event->request->method(),
                        'path' => $event->request->path(),
                    ]);
                }
            } catch (Throwable) {
                // Swallow.
            }
        });

        $this->app->terminating(function (): void {
            try {
                $uptimex = $this->app->make(Uptimex::class);

                if ($uptimex->context()?->type === ExecutionContext::TYPE_REQUEST) {
                    $uptimex->endTrace();
                }
            } catch (Throwable) {
                // Swallow.
            }
        });
    }
}
