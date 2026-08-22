<?php

declare(strict_types=1);

namespace Greenlight\Internal\Process;

/**
 * Records only the first signal. The asynchronous handler does no other work.
 * An exit code is 128 plus the signal number.
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

    public function exitCode(): ?int
    {
        return $this->signal === null ? null : 128 + $this->signal;
    }
}
