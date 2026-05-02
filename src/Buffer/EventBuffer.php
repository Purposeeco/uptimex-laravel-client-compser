<?php

namespace Uptimex\Client\Buffer;

/**
 * Bounded FIFO buffer of telemetry events. When a `record()` would exceed the
 * configured cap, the oldest event is dropped and `$dropped` is incremented —
 * we'd rather lose tail events than block the host application or balloon
 * memory in a runaway process.
 *
 * The buffer is per-execution-context: every HTTP request / Artisan command /
 * scheduled tick gets its own instance, flushed at lifecycle end.
 */
final class EventBuffer
{
    /** @var list<array<string, mixed>> */
    private array $events = [];

    private int $dropped = 0;

    public function __construct(public readonly int $capacity) {}

    public function add(array $event): void
    {
        if (count($this->events) >= $this->capacity) {
            array_shift($this->events);
            $this->dropped++;
        }

        $this->events[] = $event;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        return $this->events;
    }

    public function count(): int
    {
        return count($this->events);
    }

    public function isEmpty(): bool
    {
        return $this->events === [];
    }

    public function dropped(): int
    {
        return $this->dropped;
    }

    public function flush(): array
    {
        $events = $this->events;
        $this->events = [];

        return $events;
    }
}
