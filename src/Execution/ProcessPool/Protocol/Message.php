<?php

declare(strict_types=1);

namespace Greenlight\Execution\ProcessPool\Protocol;

use Greenlight\Internal\Wire\WireCommunicationFailed;

/**
 * Each message contains a stable short type tag. Class names do not occur on
 * the wire.
 *
 * @internal
 */
interface Message
{
    /**
     * @return non-empty-string
     */
    public static function tag(): string;

    /**
     * @internal
     *
     * @return array<string, mixed>
     */
    public function toWire(): array;

    /**
     * @internal
     *
     * @param array<string, mixed> $payload
     * @throws \InvalidArgumentException when a decoded value violates a domain invariant
     * @throws WireCommunicationFailed when the payload cannot be decoded
     */
    public static function fromWire(array $payload): static;
}
