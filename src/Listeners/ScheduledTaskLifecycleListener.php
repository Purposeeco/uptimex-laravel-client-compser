<?php

namespace Uptimex\Client\Listeners;

use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskSkipped;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\Event as ScheduleEvent;
use Throwable;
use Uptimex\Client\Context\ExecutionContext;
use Uptimex\Client\Uptimex;

/**
 * Mirrors the command lifecycle for the Laravel scheduler. Each event from
 * `Illuminate\Console\Events\ScheduledTask*` opens / records / closes a
 * dedicated `scheduled_task` trace.
 */
final class ScheduledTaskLifecycleListener
{
    /**
     * @var array<string, float>
     */
    private array $startedAtByName = [];

    public function __construct(private readonly Uptimex $uptimex) {}

    public function onStarting(ScheduledTaskStarting $event): void
    {
        if (! $this->uptimex->isEnabled()) {
            return;
        }

        $name = $this->describeTask($event->task);

        if ($this->uptimex->context() === null) {
            $this->uptimex->startTrace(ExecutionContext::TYPE_SCHEDULED_TASK, [
                'name' => $name,
                'expression' => $event->task->expression ?? null,
            ]);
        }

        $this->startedAtByName[$name] = microtime(true);
    }

    public function onFinished(ScheduledTaskFinished $event): void
    {
        $this->record($event->task, 'success');
    }

    public function onFailed(ScheduledTaskFailed $event): void
    {
        $this->record($event->task, 'failed');
    }

    public function onSkipped(ScheduledTaskSkipped $event): void
    {
        $this->record($event->task, 'skipped');
    }

    private function record(ScheduleEvent $task, string $status): void
    {
        if (! $this->uptimex->isEnabled()) {
            return;
        }

        try {
            $name = $this->describeTask($task);
            $start = $this->startedAtByName[$name] ?? null;
            $durationMs = $start !== null ? (int) round((microtime(true) - $start) * 1000) : null;
            unset($this->startedAtByName[$name]);

            if ($this->uptimex->isRecording()) {
                $this->uptimex->record('scheduled_task', [
                    'name' => mb_substr($name, 0, 190),
                    'expression' => isset($task->expression) ? mb_substr((string) $task->expression, 0, 64) : null,
                    'next_run_at' => $this->nextRunAt($task),
                    'duration_ms' => $durationMs,
                    'status' => $status,
                ]);
            }
        } finally {
            if ($this->uptimex->context()?->type === ExecutionContext::TYPE_SCHEDULED_TASK) {
                $this->uptimex->endTrace($status === 'success' ? 'ok' : $status);
            }
        }
    }

    private function describeTask(ScheduleEvent $task): string
    {
        if (! empty($task->description)) {
            return (string) $task->description;
        }

        if (property_exists($task, 'command') && $task->command !== null) {
            return (string) $task->command;
        }

        return 'closure';
    }

    private function nextRunAt(ScheduleEvent $task): ?string
    {
        try {
            return $task->nextRunDate(now())->toIso8601String();
        } catch (Throwable) {
            return null;
        }
    }
}
