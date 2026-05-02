<?php

use Illuminate\Contracts\Queue\Job as JobContract;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Uptimex\Client\Context\ExecutionContext;
use Uptimex\Client\Facades\Uptimex;
use Uptimex\Client\Listeners\JobLifecycleListener;

function fakeJob(string $name = 'App\\Jobs\\SendInvoice', string $queue = 'default', int $attempts = 1, ?string $id = 'job-1'): JobContract
{
    $mock = Mockery::mock(JobContract::class);
    $mock->shouldReceive('resolveName')->andReturn($name);
    $mock->shouldReceive('getQueue')->andReturn($queue);
    $mock->shouldReceive('attempts')->andReturn($attempts);
    $mock->shouldReceive('getJobId')->andReturn($id);

    return $mock;
}

it('opens a worker trace on JobProcessing if none active', function () {
    expect(Uptimex::context())->toBeNull();

    $listener = $this->app->make(JobLifecycleListener::class);
    $listener->onProcessing(new JobProcessing('redis', fakeJob()));

    expect(Uptimex::context()?->type)->toBe('command');
});

it('records a processed event with class + queue + duration', function () {
    Uptimex::startTrace(ExecutionContext::TYPE_COMMAND);
    $listener = $this->app->make(JobLifecycleListener::class);

    $job = fakeJob('App\\Jobs\\SendInvoice', 'mail', attempts: 1, id: 'job-x');
    $listener->onProcessing(new JobProcessing('redis', $job));
    usleep(2000); // 2 ms
    $listener->onProcessed(new JobProcessed('redis', $job));

    $events = Uptimex::buffer()?->all() ?? [];
    expect($events)->toHaveCount(1);

    $event = $events[0];
    expect($event['type'])->toBe('job')
        ->and($event['class'])->toBe('App\\Jobs\\SendInvoice')
        ->and($event['queue'])->toBe('mail')
        ->and($event['status'])->toBe('processed')
        ->and($event['duration_ms'])->toBeGreaterThanOrEqual(0);
});

it('records failed events with reason', function () {
    Uptimex::startTrace(ExecutionContext::TYPE_COMMAND);
    $listener = $this->app->make(JobLifecycleListener::class);

    $job = fakeJob(id: 'job-y');
    $listener->onProcessing(new JobProcessing('redis', $job));
    $listener->onFailed(new JobFailed('redis', $job, new RuntimeException('connection refused')));

    $events = Uptimex::buffer()?->all() ?? [];
    expect($events)->toHaveCount(1)
        ->and($events[0]['status'])->toBe('failed')
        ->and($events[0]['failed_reason'])->toBe('connection refused');
});
