<?php

namespace Uptimex\Client\Drain;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Throwable;

/**
 * Decides whether to opportunistically drain the spool after a response
 * has been sent. Keeps the "when to drain" policy out of the middleware
 * and the service provider so each stays single-responsibility.
 */
final class DrainTrigger
{
    public function __construct(
        private readonly Drainer $drainer,
        private readonly ConfigRepository $config,
    ) {}

    /**
     * Run one budgeted drain pass. Safe to call from a `terminating()`
     * hook — swallows everything, since telemetry must never break the
     * host application.
     */
    public function runAfterResponse(): void
    {
        try {
            if ($this->config->get('uptimex.delivery', 'spool') !== 'spool') {
                return;
            }
            if (! $this->config->get('uptimex.drain_auto', true)) {
                return;
            }

            $this->drainer->drain(DrainBudget::fromConfig($this->config));
        } catch (Throwable) {
            // Draining telemetry must never affect the host application.
        }
    }
}
