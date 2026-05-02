<?php

namespace Uptimex\Client\Transport;

/**
 * No-op transport used when the SDK is disabled or under tests that need to
 * assert *what* would have been sent without actually opening a socket. Each
 * `send()` retains the batch in memory so tests can introspect it via
 * `sentBatches()`.
 */
final class NullTransport implements Transport
{
    /** @var list<array<string, mixed>> */
    private array $sent = [];

    /** @var list<array<string, mixed>> */
    private array $deploys = [];

    public function send(array $batch): bool
    {
        $this->sent[] = $batch;

        return true;
    }

    public function sendDeploy(array $payload): ?array
    {
        $this->deploys[] = $payload;

        return ['deployment_id' => count($this->deploys), 'idempotent' => false];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function sentBatches(): array
    {
        return $this->sent;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function sentDeploys(): array
    {
        return $this->deploys;
    }

    public function reset(): void
    {
        $this->sent = [];
        $this->deploys = [];
    }
}
