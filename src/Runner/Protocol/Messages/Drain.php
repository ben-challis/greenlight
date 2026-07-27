<?php

declare(strict_types=1);

namespace Greenlight\Runner\Protocol\Messages;

use Greenlight\Runner\Protocol\Message;

/**
 * Tells a worker to complete the current test, send Done, and exit.
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
