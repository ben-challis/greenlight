<?php

declare(strict_types=1);

namespace Greenlight\Core;

/**
 * Records only the first signal and does no work in the async handler.
 * Exit codes follow the conventional 128 plus signal number.
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
