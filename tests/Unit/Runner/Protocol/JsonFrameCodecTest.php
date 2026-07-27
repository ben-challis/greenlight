<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Runner\Protocol;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Expect\Fail;
use Greenlight\Runner\Protocol\JsonFrameCodec;
use Greenlight\Runner\Protocol\ProtocolError;

final class JsonFrameCodecTest
{
    #[Test]
    public function unsupportedPayloadValuesProduceAProtocolError(): void
    {
        $stream = \fopen('php://memory', 'r');

        if ($stream === false) {
            Fail::because('Expected PHP to open the in-memory stream.');
        }

        try {
            Expect::that(static fn(): string => new JsonFrameCodec()->encode(['stream' => $stream]))
                ->because('unsupported JSON values produce a protocol error')
                ->toThrow(
                    ProtocolError::class,
                    matching: '/Malformed frame: Greenlight cannot encode the payload as JSON:/',
                );
        } finally {
            \fclose($stream);
        }
    }

    #[Test]
    public function malformedJsonBodyProducesAProtocolError(): void
    {
        $codec = new JsonFrameCodec();

        Expect::that(static fn(): array => $codec->decode('{]'))
            ->because('malformed JSON produces a protocol error')
            ->toThrow(
                ProtocolError::class,
                matching: '/Malformed frame: body is not valid JSON:/',
            );
    }

    #[Test]
    public function scalarJsonBodyProducesAProtocolError(): void
    {
        $codec = new JsonFrameCodec();

        Expect::that(static fn(): array => $codec->decode('null'))
            ->because('a JSON scalar is not a protocol envelope')
            ->toThrow(
                ProtocolError::class,
                message: 'Malformed frame: body decodes to null, not a map.',
            );
    }
}
