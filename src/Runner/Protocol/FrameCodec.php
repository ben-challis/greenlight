<?php

declare(strict_types=1);

namespace Greenlight\Runner\Protocol;

/**
 * Turns an envelope array into framed bytes and back. JSON is the v1
 * encoding; the interface exists so a binary encoding can replace it if
 * measurement ever justifies the swap.
 *
 * @internal
 */
interface FrameCodec
{
    /**
     * @param array<string, mixed> $envelope
     *
     * @return non-empty-string the full frame, length prefix included
     *
     * @throws ProtocolError
     */
    public function encode(array $envelope): string;

    /**
     * @param non-empty-string $body the frame body, length prefix already stripped
     *
     * @return array<string, mixed>
     *
     * @throws ProtocolError
     */
    public function decode(string $body): array;
}
