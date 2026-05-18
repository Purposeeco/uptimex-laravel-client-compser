<?php

namespace Uptimex\Client\Agent;

use RuntimeException;

/**
 * A frame on the agent socket was malformed — truncated, oversized, or its
 * declared length did not match its payload.
 */
final class FrameException extends RuntimeException
{
}
