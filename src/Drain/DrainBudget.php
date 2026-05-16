<?php

namespace Uptimex\Client\Drain;

use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * Caps a single drain pass so a request never over-spends after its
 * response is sent: at most $maxBatches files, or $maxSeconds of wall
 * time, whichever is reached first.
 */
final class DrainBudget
{
    public function __construct(
        public readonly int $maxBatches,
        public readonly float $maxSeconds,
    ) {}

    /**
     * The opportunistic per-request budget, from config.
     */
    public static function fromConfig(ConfigRepository $config): self
    {
        return new self(
            maxBatches: max(1, (int) $config->get('uptimex.drain_max_batches', 20)),
            maxSeconds: max(1, (int) $config->get('uptimex.drain_max_ms', 750)) / 1000,
        );
    }
}
