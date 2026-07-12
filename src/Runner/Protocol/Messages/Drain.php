<?php

declare(strict_types=1);

namespace Greenlight\Runner\Protocol\Messages;

use Greenlight\Runner\Protocol\Message;

/**
 * Orchestrator to worker: finish the current test, send done, exit.
 *
 * @internal
 */
final readonly class Drain implements Message
{
    #[\Override]
    public static function tag(): string
    {
        return 'drain';
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
