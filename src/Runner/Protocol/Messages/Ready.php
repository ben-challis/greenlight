<?php

declare(strict_types=1);

namespace Greenlight\Runner\Protocol\Messages;

use Greenlight\Runner\Protocol\Message;

/**
 * Worker to orchestrator: bootstrap completed and assignments may begin.
 *
 * @internal
 */
final readonly class Ready implements Message
{
    #[\Override]
    public static function tag(): string
    {
        return 'ready';
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
