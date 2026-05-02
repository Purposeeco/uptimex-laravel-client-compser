<?php

namespace Uptimex\Client\Listeners;

use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobQueued;
use Illuminate\Queue\Events\JobReleasedAfterException;
use Uptimex\Client\Context\ExecutionContext;
use Uptimex\Client\Uptimex;

/**
 * Records `events_jobs` rows for every queue lifecycle transition. The
 * worker process and the dispatching process both fire events; we record
 * what we can from each:
 *
 *   - JobQueued (in dispatcher)              → status='queued'
 *   - JobProcessing (in worker)              → start a trace if none active, status not yet recorded
 *   - JobProcessed (in worker)               → status='processed', durations included
 *   - JobReleasedAfterException (in worker)  → status='released'
 *   - JobFailed (in worker)                  → status='failed' with reason
 */
final class JobLifecycleListener
{
    /**
     * @var array<string, float>
     */
    private array $startedAtById = [];

    public function __construct(private readonly Uptimex $uptimex) {}

    public function onQueued(JobQueued $event): void
    {
        if (! $this->uptimex->isEnabled() || $this->uptimex->context() === null) {
            return;
        }

        $this->uptimex->record('job', [
            'class' => is_object($event->job) ? $event->job::class : (string) $event->job,
            'queue' => method_exists($event, 'queue') ? $event->queue : null,
            'connection_name' => $event->connectionName ?? null,
            'attempts' => 0,
            'status' => 'queued',
        ]);
    }

    public function onProcessing(JobProcessing $event): void
    {
        if (! $this->uptimex->isEnabled()) {
            return;
        }

        // Open a trace context if the worker doesn't already have one.
        if ($this->uptimex->context() === null) {
            $this->uptimex->startTrace(ExecutionContext::TYPE_COMMAND, [
                'name' => 'queue:work',
                'job' => $event->job->resolveName(),
            ]);
        }

        $this->startedAtById[$event->job->getJobId()] = microtime(true);
    }

    public function onProcessed(JobProcessed $event): void
    {
        if (! $this->uptimex->isEnabled() || $this->uptimex->context() === null) {
            return;
        }

        $this->uptimex->record('job', [
            'duration_ms' => $this->durationFor($event->job->getJobId()),
            'class' => $event->job->resolveName(),
            'queue' => $event->job->getQueue(),
            'connection_name' => $event->connectionName,
            'attempts' => $event->job->attempts(),
            'status' => 'processed',
        ]);
    }

    public function onReleased(JobReleasedAfterException $event): void
    {
        if (! $this->uptimex->isEnabled() || $this->uptimex->context() === null) {
            return;
        }

        $this->uptimex->record('job', [
            'duration_ms' => $this->durationFor($event->job->getJobId()),
            'class' => $event->job->resolveName(),
            'queue' => $event->job->getQueue(),
            'connection_name' => $event->connectionName,
            'attempts' => $event->job->attempts(),
            'status' => 'released',
        ]);
    }

    public function onFailed(JobFailed $event): void
    {
        if (! $this->uptimex->isEnabled() || $this->uptimex->context() === null) {
            return;
        }

        $this->uptimex->record('job', [
            'duration_ms' => $this->durationFor($event->job->getJobId()),
            'class' => $event->job->resolveName(),
            'queue' => $event->job->getQueue(),
            'connection_name' => $event->connectionName,
            'attempts' => $event->job->attempts(),
            'status' => 'failed',
            'failed_reason' => mb_substr((string) $event->exception->getMessage(), 0, 65000),
        ]);
    }

    private function durationFor(?string $jobId): ?int
    {
        if ($jobId === null || ! isset($this->startedAtById[$jobId])) {
            return null;
        }

        $duration = (int) round((microtime(true) - $this->startedAtById[$jobId]) * 1000);
        unset($this->startedAtById[$jobId]);

        return $duration;
    }
}
