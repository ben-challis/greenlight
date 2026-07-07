<?php

declare(strict_types=1);

namespace Greenlight\Runner\Protocol;

/**
 * Encodes length-prefixed JSON frames: a 4-byte big-endian unsigned length
 * followed by that many bytes of JSON.
 *
 * Invalid UTF-8 is substituted at encode time as defence in depth behind
 * capture-side scrubbing.
 *
 * @internal
 */
final readonly class JsonFrameCodec implements FrameCodec
{
    public const int DEFAULT_MAX_FRAME_BYTES = 8 * 1024 * 1024;

    public function __construct(
        public int $maxFrameBytes = self::DEFAULT_MAX_FRAME_BYTES,
    ) {}

    #[\Override]
    public function encode(array $envelope): string
    {
        try {
            $json = \json_encode($envelope, \JSON_THROW_ON_ERROR | \JSON_INVALID_UTF8_SUBSTITUTE);
        } catch (\JsonException $e) {
            throw ProtocolError::malformedFrame('payload cannot be JSON encoded: ' . $e->getMessage());
        }

        $length = \strlen($json);

        if ($length > $this->maxFrameBytes) {
            throw ProtocolError::frameTooLarge($length, $this->maxFrameBytes);
        }

        return \pack('N', $length) . $json;
    }

    #[\Override]
    public function decode(string $body): array
    {
        try {
            $decoded = \json_decode($body, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw ProtocolError::malformedFrame('body is not valid JSON: ' . $e->getMessage());
        }

        if (!\is_array($decoded)) {
            throw ProtocolError::malformedFrame('body decodes to ' . \get_debug_type($decoded) . ', not a map');
        }

        $envelope = [];

        foreach ($decoded as $key => $value) {
            $envelope[(string) $key] = $value;
        }

        return $envelope;
    }
}
