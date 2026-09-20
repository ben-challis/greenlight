<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Execution\ProcessPool\Protocol;

use Greenlight\Attribute\Test;
use Greenlight\Execution\ProcessPool\Protocol\FrameBuffer;
use Greenlight\Execution\ProcessPool\Protocol\JsonFrameCodec;

use function Greenlight\expect;

final readonly class FrameMinimumSizeTest
{
    #[Test]
    public function aOneByteFrameIsAValidProtocolLimit(): void
    {
        $codec = new JsonFrameCodec(1);
        $buffer = new FrameBuffer(1);
        $buffer->feed(\pack('N', 1) . 'x');

        expect($codec->maxFrameBytes)
            ->because('the protocol MUST support the smallest valid frame')
            ->toBe(1);
        expect($buffer->next())
            ->toBe('x');
    }
}
