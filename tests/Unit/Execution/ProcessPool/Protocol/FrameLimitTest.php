<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Execution\ProcessPool\Protocol;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Execution\ProcessPool\Protocol\FrameBuffer;
use Greenlight\Execution\ProcessPool\Protocol\JsonFrameCodec;
use Greenlight\Expect\Expect;

final readonly class FrameLimitTest
{
    #[Test]
    #[DataSet('invalidLimits')]
    public function frameLimitsMustBePositive(int $limit): void
    {
        Expect::that(static fn(): JsonFrameCodec => new JsonFrameCodec($limit))
            ->because('the encoder MUST reject a frame limit that cannot contain a frame')
            ->toThrow(
                \InvalidArgumentException::class,
                message: 'Maximum frame size must be greater than zero.',
            );

        Expect::that(static fn(): FrameBuffer => new FrameBuffer($limit))
            ->because('the decoder MUST reject a frame limit that cannot contain a frame')
            ->toThrow(
                \InvalidArgumentException::class,
                message: 'Maximum frame size must be greater than zero.',
            );
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function invalidLimits(): iterable
    {
        yield 'zero' => [0];
        yield 'negative' => [-1];
    }
}
