<?php

declare(strict_types=1);

namespace Greenlight\Execution\ProcessPool\Protocol\Messages;

use Greenlight\Execution\ProcessPool\Protocol\Message;

/**
 * Orchestrator to worker: the attempt output window is active.
 *
 * @internal
 */
final readonly class AttemptReady implements Message
{
    #[\Override]
    public static function tag(): string
    {
        return 'attempt-ready';
    }

    #[\Override]
    public function toWire(): array
    {
        return [];
    }

    #[\Override]
    public static function fromWire(array $payload): static
    {
        return new self();
    }
}
