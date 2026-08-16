<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Runner\Protocol;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Expect\Fail;
use Greenlight\Runner\Protocol\FrameBuffer;
use Greenlight\Runner\Protocol\JsonFrameCodec;

final class ExactFrameLimitTest
{
    private const int LIMIT = 64;

    #[Test]
    public function bodyAtTheExactFrameLimitRoundTrips(): void
    {
        $emptyEnvelope = ['payload' => ''];
        $emptyJson = \json_encode($emptyEnvelope, \JSON_THROW_ON_ERROR);
        $envelope = [
            'payload' => \str_repeat('x', self::LIMIT - \strlen($emptyJson)),
        ];
        $codec = new JsonFrameCodec(self::LIMIT);
        $frame = $codec->encode($envelope);
        $buffer = new FrameBuffer(self::LIMIT);
        $buffer->feed($frame);
        $body = $buffer->next();

        Expect::that(\strlen($frame))
            ->because('the framed body MUST be exactly the configured limit')
            ->toBe(self::LIMIT + 4);

        if ($body === null) {
            Fail::because('A frame body at the configured limit MUST be accepted.');
        }

        Expect::that($codec->decode($body))
            ->because('the exact-limit frame MUST survive the protocol round trip')
            ->toBe($envelope);
        Expect::that($buffer->hasPendingBytes())
            ->toBeFalse();
    }

    #[Test]
    public function unicodeBodyAtTheExactByteLimitRoundTrips(): void
    {
        $emptyEnvelope = ['payload' => '€'];
        $emptyJson = \json_encode($emptyEnvelope, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE);
        $envelope = [
            'payload' => '€' . \str_repeat('x', self::LIMIT - \strlen($emptyJson)),
        ];
        $body = \json_encode($envelope, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE);

        Expect::that(\strlen($body))
            ->because('the Unicode frame fixture MUST reach the exact byte limit')
            ->toBe(self::LIMIT);

        $buffer = new FrameBuffer(self::LIMIT);
        $buffer->feed(\pack('N', \strlen($body)) . $body);
        $decodedBody = $buffer->next();

        if ($decodedBody === null) {
            Fail::because('A Unicode frame body at the configured byte limit MUST be complete.');
        }

        Expect::that(new JsonFrameCodec(self::LIMIT)->decode($decodedBody))
            ->because('frame lengths MUST count Unicode bytes, not characters')
            ->toBe($envelope);
        Expect::that($buffer->hasPendingBytes())
            ->toBeFalse();
    }
}
