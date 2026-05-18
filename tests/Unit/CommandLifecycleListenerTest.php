<?php

use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Uptimex\Client\Facades\Uptimex;
use Uptimex\Client\Listeners\CommandLifecycleListener;

it('records a command event with name + exit code on Finished', function () {
    expect(Uptimex::context())->toBeNull();

    $listener = $this->app->make(CommandLifecycleListener::class);
    $listener->onStarting(new CommandStarting('app:do-thing', new ArgvInput(['app']), new BufferedOutput));
    expect(Uptimex::context()?->type)->toBe('command');

    $listener->onFinished(new CommandFinished('app:do-thing', new ArgvInput(['app']), new BufferedOutput, 0));

    // Trace ended → buffer null afterward, but the test transport collected the batch.
    $batch = $this->transport->sentBatches()[0] ?? null;
    expect($batch)->not->toBeNull();
    $event = $batch['events'][0];
    expect($event['type'])->toBe('command')
        ->and($event['name'])->toBe('app:do-thing')
        ->and($event['exit_code'])->toBe(0)
        ->and($event['status'])->toBe('success');
});

it('records non-zero exit code as failed', function () {
    $listener = $this->app->make(CommandLifecycleListener::class);
    $listener->onStarting(new CommandStarting('app:bad', new ArgvInput(['app']), new BufferedOutput));
    $listener->onFinished(new CommandFinished('app:bad', new ArgvInput(['app']), new BufferedOutput, 1));

    $event = $this->transport->sentBatches()[0]['events'][0];
    expect($event['exit_code'])->toBe(1)
        ->and($event['status'])->toBe('failed');
});

it('never traces an SDK or scheduler-dispatcher command', function (string $command) {
    // `uptimex:*` self-flush (and `uptimex:agent` is long-running — tracing it
    // would open a trace that never ends); `schedule:run` fires every minute
    // and would emit idle telemetry. The real scheduled tasks are captured by
    // ScheduledTaskLifecycleListener regardless.
    $listener = $this->app->make(CommandLifecycleListener::class);
    $listener->onStarting(new CommandStarting($command, new ArgvInput(['app']), new BufferedOutput));

    expect(Uptimex::context())->toBeNull();
})->with([
    'uptimex:test',
    'uptimex:status',
    'uptimex:agent',
    'uptimex:deploy',
    'schedule:run',
    'schedule:work',
]);
