<?php

declare(strict_types=1);

namespace Greenlight\Internal\Process;

/**
 * Records only the first signal. The asynchronous handler does no other work.
 *
 * @internal
 */
final class GracefulShutdown
{
    private ?int $signal = null;

    public function request(int $signal): void
    {
        $this->signal ??= $signal;
    }

    public function requested(): bool
    {
        return $this->signal !== null;
    }

    public function signal(): ?int
    {
        return $this->signal;
    }
}
