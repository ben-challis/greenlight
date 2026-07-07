<?php

declare(strict_types=1);

namespace Greenlight\Core;

/**
 * A shutdown request shared between the signal handler and the run loops.
 *
 * request() records the first signal and ignores later ones, so the exit
 * code always reflects the signal that started the shutdown. It does no
 * other work, which keeps it safe to call from an async signal handler.
 *
 * requested() is polled by run loops as ordinary control flow: when it
 * turns true, the loop stops assigning work and drains through its normal
 * shutdown path instead of dying in the handler.
 *
 * exitCode() maps the recorded signal to the conventional 128 plus signal
 * number (130 for SIGINT, 143 for SIGTERM), or null while no shutdown has
 * been requested.
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
