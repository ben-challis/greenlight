<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Runner\Protocol;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Runner\Protocol\FrameBuffer;
use Greenlight\Runner\Protocol\JsonFrameCodec;

final readonly class FrameMinimumSizeTest
{
    #[Test]
    public function aOneByteFrameIsAValidProtocolLimit(): void
    {
        $codec = new JsonFrameCodec(1);
        $buffer = new FrameBuffer(1);
        $buffer->feed(\pack('N', 1) . 'x');

        Expect::that($codec->maxFrameBytes)
            ->because('the protocol MUST support the smallest valid frame')
            ->toBe(1)
            ->and($buffer->next())
            ->toBe('x');
    }
}
