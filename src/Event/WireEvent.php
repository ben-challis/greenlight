<?php

declare(strict_types=1);

namespace Greenlight\Event;

use Greenlight\Internal\Wire\WireCommunicationFailed;

/**
 * Supplies the payload values that the internal event codec reads and writes.
 *
 * @internal
 */
interface WireEvent extends Event
{
    /**
     * @return array<string, mixed>
     */
    public function toWire(): array;

    /**
     * @param array<string, mixed> $payload
     * @throws \InvalidArgumentException when a decoded value violates a domain invariant
     * @throws WireCommunicationFailed when the payload cannot be decoded
     */
    public static function fromWire(array $payload): static;
}
