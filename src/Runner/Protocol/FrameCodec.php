<?php

declare(strict_types=1);

namespace Greenlight\Runner\Protocol;

/**
 * Version 1 uses JSON.
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
