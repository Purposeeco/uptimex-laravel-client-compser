<?php

namespace Uptimex\Client\Buffer;

/**
 * Bounded FIFO buffer of telemetry events, backed by a ring buffer so an
 * overflowing `add()` stays O(1) — a runaway request emitting tens of
 * thousands of events never pays an O(n) shuffle. When a `record()` would
 * exceed the cap the oldest event is overwritten and `$dropped` is
 * incremented: we drop tail events rather than block the host application
 * or balloon memory.
 *
 * The buffer is per-execution-context: every HTTP request / Artisan
 * command / scheduled tick gets its own instance, flushed at lifecycle end.
 */
final class EventBuffer
{
    public readonly int $capacity;

    /** @var array<int, array<string, mixed>> */
    private array $slots = [];

    /** Index the next event will be written to. */
    private int $head = 0;

    /** Number of events currently buffered (0..capacity). */
    private int $size = 0;

    private int $dropped = 0;

    public function __construct(int $capacity)
    {
        $this->capacity = max(1, $capacity);
    }

    public function add(array $event): void
    {
        $this->slots[$this->head] = $event;
        $this->head = ($this->head + 1) % $this->capacity;

        if ($this->size === $this->capacity) {
            // Buffer full — we just overwrote the oldest event.
            $this->dropped++;
        } else {
            $this->size++;
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        return $this->ordered();
    }

    public function count(): int
    {
        return $this->size;
    }

    public function isEmpty(): bool
    {
        return $this->size === 0;
    }

    public function dropped(): int
    {
        return $this->dropped;
    }

    /**
     * Drain the buffer, returning events in insertion (oldest-first) order.
     *
     * @return list<array<string, mixed>>
     */
    public function flush(): array
    {
        $events = $this->ordered();

        $this->slots = [];
        $this->head = 0;
        $this->size = 0;

        return $events;
    }

    /**
     * The buffered events in oldest-first insertion order.
     *
     * @return list<array<string, mixed>>
     */
    private function ordered(): array
    {
        if ($this->size === 0) {
            return [];
        }

        $start = ($this->head - $this->size + $this->capacity) % $this->capacity;

        $events = [];
        for ($i = 0; $i < $this->size; $i++) {
            $events[] = $this->slots[($start + $i) % $this->capacity];
        }

        return $events;
    }
}
