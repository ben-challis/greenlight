<?php

declare(strict_types=1);

namespace Greenlight\Runner\Protocol;

/**
 * Encodes JSON frames with a length prefix. The prefix is a four-byte, unsigned,
 * big-endian length. The JSON body contains that number of bytes.
 *
 * Output capture already replaces invalid UTF-8. The encoder repeats this
 * protection.
 *
 * @internal
 */
final readonly class JsonFrameCodec implements FrameCodec
{
    public const int DEFAULT_MAX_FRAME_BYTES = 8 * 1024 * 1024;

    /**
     * @var positive-int
     */
    public int $maxFrameBytes;

    /**
     * @throws \InvalidArgumentException
     */
    public function __construct(int $maxFrameBytes = self::DEFAULT_MAX_FRAME_BYTES)
    {
        if ($maxFrameBytes < 1) {
            throw new \InvalidArgumentException('Maximum frame size must be greater than zero.');
        }

        $this->maxFrameBytes = $maxFrameBytes;
    }

    #[\Override]
    public function encode(array $envelope): string
    {
        try {
            $json = \json_encode($envelope, \JSON_THROW_ON_ERROR | \JSON_INVALID_UTF8_SUBSTITUTE);
        } catch (\JsonException $e) {
            throw ProtocolError::malformedFrame('Greenlight cannot encode the payload as JSON: ' . $e->getMessage());
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

        if (!\is_array($decoded)
            || (\array_is_list($decoded) && !\str_starts_with(\ltrim($body), '{'))
        ) {
            $type = \is_array($decoded) ? 'list' : \get_debug_type($decoded);

            throw ProtocolError::malformedFrame('body decodes to ' . $type . ', not a map');
        }

        $envelope = [];

        foreach ($decoded as $key => $value) {
            $envelope[(string) $key] = $value;
        }

        return $envelope;
    }
}
