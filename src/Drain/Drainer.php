<?php

namespace Uptimex\Client\Drain;

/**
 * Ships pending spool batches to the UptimeX server.
 *
 * An interface so a future dedicated agent daemon is just a different
 * implementation invoked from a long-running process rather than from a
 * post-response request hook.
 */
interface Drainer
{
    /**
     * Drain the spool within the given budget. MUST NOT throw.
     */
    public function drain(DrainBudget $budget): DrainResult;
}
