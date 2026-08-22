<?php

declare(strict_types=1);

namespace Greenlight\Core\Wire;

/**
 * Defines values that cross the boundary between an orchestrator and a worker.
 *
 * A JSON encode and decode operation must preserve the payload. Keys are
 * strings. Values are scalars, null, or nested arrays of these types. Do not
 * use PHP `serialize()`.
 */
interface WireSerializable
{
    /**
     * @return array<string, mixed>
     */
    public function toWire(): array;

    /**
     * @param array<string, mixed> $payload
     *
     * @throws \InvalidArgumentException when a decoded value violates a domain invariant
     * @throws WireCommunicationFailed when the payload cannot be decoded
     */
    public static function fromWire(array $payload): static;
}
