<?php

namespace Uptimex\Client\Spool;

/**
 * An immutable, finished telemetry batch — the unit of "zero data loss".
 *
 * Replaces the bare batch array `endTrace()` used to hand straight to the
 * transport. It owns its own (de)serialization so the spool, the
 * dispatchers and the drainer all share one typed contract.
 */
final class SpooledBatch
{
    /**
     * @param  array<string, mixed>|null  $context
     * @param  list<array<string, mixed>>  $events
     */
    public function __construct(
        public readonly string $batchUuid,
        public readonly string $sdkVersion,
        public readonly ?string $host,
        public readonly ?float $sampleRate,
        public readonly ?array $context,
        public readonly array $events,
    ) {}

    /**
     * The array shape POSTed to the ingest endpoint — identical to the
     * batch array the SDK has always sent.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'batch_uuid' => $this->batchUuid,
            'sdk_version' => $this->sdkVersion,
            'host' => $this->host,
            'sample_rate' => $this->sampleRate,
            'context' => $this->context,
            'events' => $this->events,
        ];
    }

    /**
     * Rehydrate from a decoded spool-file payload.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $context = $data['context'] ?? null;
        $events = $data['events'] ?? [];

        return new self(
            batchUuid: (string) ($data['batch_uuid'] ?? ''),
            sdkVersion: (string) ($data['sdk_version'] ?? ''),
            host: isset($data['host']) ? (string) $data['host'] : null,
            sampleRate: isset($data['sample_rate']) ? (float) $data['sample_rate'] : null,
            context: is_array($context) ? $context : null,
            events: is_array($events) ? array_values($events) : [],
        );
    }

    public function isEmpty(): bool
    {
        return $this->events === [];
    }

    public function eventCount(): int
    {
        return count($this->events);
    }
}
