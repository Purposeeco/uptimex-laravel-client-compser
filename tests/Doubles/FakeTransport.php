<?php

namespace Uptimex\Client\Tests\Doubles;

use RuntimeException;
use Uptimex\Client\Transport\Transport;

/**
 * A controllable {@see Transport} test double — succeed, fail, or throw.
 */
final class FakeTransport implements Transport
{
    /** @var list<array<string, mixed>> */
    public array $sent = [];

    public int $sendCalls = 0;

    public function __construct(
        private bool $succeeds = true,
        private bool $throws = false,
    ) {}

    public function send(array $batch): bool
    {
        $this->sendCalls++;

        if ($this->throws) {
            throw new RuntimeException('fake transport failure');
        }

        if ($this->succeeds) {
            $this->sent[] = $batch;
        }

        return $this->succeeds;
    }

    public function sendDeploy(array $payload): ?array
    {
        return $this->succeeds ? ['ok' => true] : null;
    }

    public function fail(): self
    {
        $this->succeeds = false;

        return $this;
    }
}
