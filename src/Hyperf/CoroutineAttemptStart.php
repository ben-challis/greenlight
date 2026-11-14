<?php

declare(strict_types=1);

namespace Greenlight\Hyperf;

/**
 * Records that a test coroutine finished its container setup and started the
 * test attempt.
 *
 * The waiting coroutine reads `reached()` to know when the attempt can set its
 * deadline. Setup before that point can take any time.
 *
 * @internal
 */
final class CoroutineAttemptStart
{
    private bool $reached = false;

    public function mark(): void
    {
        $this->reached = true;
    }

    public function reached(): bool
    {
        return $this->reached;
    }
}
