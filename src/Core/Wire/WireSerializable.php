<?php

declare(strict_types=1);

namespace Greenlight\Core\Wire;

/**
 * Contract for values that cross the orchestrator/worker process boundary.
 * Payloads must survive a JSON round trip: keys are strings, values are
 * scalars, null, or nested arrays of the same. PHP serialize() is banned.
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
     * @throws InvalidWirePayload
     */
    public static function fromWire(array $payload): static;
}
