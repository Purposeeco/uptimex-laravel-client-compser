<?php

namespace Uptimex\Client\Delivery;

use Uptimex\Client\Spool\SpooledBatch;

/**
 * No-op dispatcher, bound when the SDK is disabled or has no token.
 */
final class NullDispatcher implements BatchDispatcher
{
    public function dispatch(SpooledBatch $batch): bool
    {
        return true;
    }
}
