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
use Uptimex\Client\Agent\AgentClient;
use Uptimex\Client\Console\AgentCommand;
use Uptimex\Client\Console\DeployCommand;
use Uptimex\Client\Console\InstallCommand;
use Uptimex\Client\Console\StatusCommand;
use Uptimex\Client\Console\TestCommand;
use Uptimex\Client\Context\ExecutionContext;
use Uptimex\Client\Delivery\BatchDispatcher;
use Uptimex\Client\Delivery\DirectDispatcher;
use Uptimex\Client\Delivery\NullDispatcher;
use Uptimex\Client\Delivery\SocketDispatcher;
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
use Uptimex\Client\Logging\UptimexLogChannel;
use Uptimex\Client\Support\Clock;
use Uptimex\Client\Support\SystemClock;
use Uptimex\Client\Transport\HttpTransport;
use Uptimex\Client\Transport\NullTransport;
use Uptimex\Client\Transport\Transport;

class UptimexServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/uptimex.php', 'uptimex');

        // The low-level HTTP wire. It is no longer called from the request
        // path — only the agent daemon's shipper uses it.
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

        $this->registerAgentServices();
        $this->registerLogChannel();

        // DirectDispatcher is also bound concretely so `uptimex:test` can
        // demand a real synchronous round-trip regardless of `delivery`.
        $this->app->bind(DirectDispatcher::class, function (Application $app): DirectDispatcher {
            return new DirectDispatcher($app->make(Transport::class));
        });

        // The delivery strategy endTrace() hands finished batches to.
        $this->app->singleton(BatchDispatcher::class, function (Application $app): BatchDispatcher {
            $config = $app['config'];

            if (! $config->get('uptimex.enabled', true) || ! $config->get('uptimex.token')) {
                return new NullDispatcher;
            }

            $delivery = (string) $config->get('uptimex.delivery', 'direct');

            // Serverless runtimes (Vapor / Lambda) cannot host a persistent
            // agent, so deliver inline at the end of the request instead.
            if ($delivery === 'agent' && $this->isServerless()) {
                $delivery = 'direct';
            }

            return match ($delivery) {
                'agent' => new SocketDispatcher(
                    agent: $app->make(AgentClient::class),
                    fallback: $app->make(DirectDispatcher::class),
                ),
                default => $app->make(DirectDispatcher::class),
            };
        });

        $this->app->singleton(Uptimex::class, function (Application $app): Uptimex {
            return new Uptimex(
                config: $app['config'],
                dispatcher: $app->make(BatchDispatcher::class),
            );
        });
    }

    /**
     * Bind the agent client — the SDK's loopback-socket handle to the local
     * `uptimex:agent` daemon. `Clock` is shared with the agent and tests.
     */
    private function registerAgentServices(): void
    {
        $this->app->singleton(Clock::class, SystemClock::class);

        $this->app->singleton(AgentClient::class, function (Application $app): AgentClient {
            $config = $app['config'];

            return new AgentClient(
                address: (string) $config->get('uptimex.agent_address', '127.0.0.1:9237'),
                connectTimeoutMs: (int) $config->get('uptimex.agent_connect_timeout_ms', 50),
            );
        });
    }

    /**
     * Auto-register the `uptimex` log channel into the host's logging config
     * so capturing logs needs no `config/logging.php` edit — the operator
     * just adds `uptimex` to LOG_STACK. A host-defined channel of the same
     * name is left untouched.
     */
    private function registerLogChannel(): void
    {
        $config = $this->app['config'];

        if ($config->get('logging.channels.uptimex') !== null) {
            return;
        }

        $config->set('logging.channels.uptimex', [
            'driver' => 'custom',
            'via' => UptimexLogChannel::class,
            'level' => env('UPTIMEX_LOG_LEVEL', 'debug'),
        ]);
    }

    /**
     * Detect a serverless runtime (Vapor / AWS Lambda) where the local disk
     * is ephemeral and the container freezes after the response.
     */
    private function isServerless(): bool
    {
        foreach (['VAPOR_SSM_PATH', 'VAPOR_ARTIFACT_NAME', 'AWS_LAMBDA_FUNCTION_NAME', 'LAMBDA_TASK_ROOT', 'AWS_EXECUTION_ENV'] as $marker) {
            if (env($marker) !== null) {
                return true;
            }
        }

        return false;
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/uptimex.php' => config_path('uptimex.php'),
        ], 'uptimex-config');

        if ($this->app->runningInConsole()) {
            $this->commands([
                AgentCommand::class,
                InstallCommand::class,
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

    /**
     * Register an event listener whose handler can NEVER throw into the host
     * application. Telemetry is observability — if a listener fails (a bug, a
     * version skew, anything at all) the host must not even notice. Every SDK
     * event listener is registered through here, so it is structurally
     * impossible for the SDK to break the host via an event listener.
     */
    private function listenSafely(string $event, string $listener, string $method): void
    {
        Event::listen($event, function (object $eventObject) use ($listener, $method): void {
            try {
                $this->app->make($listener)->{$method}($eventObject);
            } catch (Throwable) {
                // Observability code must never break the host application.
            }
        });
    }

    private function registerCommandLifecycleListeners(): void
    {
        $this->listenSafely(CommandStarting::class, CommandLifecycleListener::class, 'onStarting');
        $this->listenSafely(CommandFinished::class, CommandLifecycleListener::class, 'onFinished');
    }

    private function registerScheduledTaskLifecycleListeners(): void
    {
        $this->listenSafely(ScheduledTaskStarting::class, ScheduledTaskLifecycleListener::class, 'onStarting');
        $this->listenSafely(ScheduledTaskFinished::class, ScheduledTaskLifecycleListener::class, 'onFinished');
        $this->listenSafely(ScheduledTaskFailed::class, ScheduledTaskLifecycleListener::class, 'onFailed');
        $this->listenSafely(ScheduledTaskSkipped::class, ScheduledTaskLifecycleListener::class, 'onSkipped');
    }

    private function registerMailListener(): void
    {
        $this->listenSafely(MessageSent::class, MailListener::class, 'handle');
    }

    private function registerNotificationListener(): void
    {
        $this->listenSafely(NotificationSent::class, NotificationListener::class, 'onSent');
        $this->listenSafely(NotificationFailed::class, NotificationListener::class, 'onFailed');
    }

    private function registerOutgoingRequestListeners(): void
    {
        $this->listenSafely(RequestSending::class, OutgoingRequestMiddleware::class, 'onSending');
        $this->listenSafely(ResponseReceived::class, OutgoingRequestMiddleware::class, 'onReceived');
        $this->listenSafely(ConnectionFailed::class, OutgoingRequestMiddleware::class, 'onConnectionFailed');
    }

    /**
     * Subscribe to Laravel's queue lifecycle events. The same listener
     * instance handles all five so per-job timing state survives across the
     * Processing → Processed pair.
     */
    private function registerJobLifecycleListeners(): void
    {
        $this->listenSafely(JobQueued::class, JobLifecycleListener::class, 'onQueued');
        $this->listenSafely(JobProcessing::class, JobLifecycleListener::class, 'onProcessing');
        $this->listenSafely(JobProcessed::class, JobLifecycleListener::class, 'onProcessed');
        $this->listenSafely(JobReleasedAfterException::class, JobLifecycleListener::class, 'onReleased');
        $this->listenSafely(JobFailed::class, JobLifecycleListener::class, 'onFailed');
    }

    /**
     * Subscribe to the four built-in cache events. Note: Laravel doesn't
     * currently fire a `CacheFailed` event; cache fail rows are emitted by
     * downstream wrappers calling `Uptimex::record('cache', …)` directly.
     */
    private function registerCacheListeners(): void
    {
        $this->listenSafely(CacheHit::class, CacheListener::class, 'onHit');
        $this->listenSafely(CacheMissed::class, CacheListener::class, 'onMissed');
        $this->listenSafely(KeyWritten::class, CacheListener::class, 'onWritten');
        $this->listenSafely(KeyForgotten::class, CacheListener::class, 'onForgotten');
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
        $this->listenSafely(QueryExecuted::class, QueryListener::class, 'handle');
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
