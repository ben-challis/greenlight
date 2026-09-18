<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Result;

use Greenlight\Attribute\Test;
use Greenlight\Result\FailureDetail;
use Greenlight\Tests\Support\JsonWire;

use function Greenlight\expect;

final readonly class FailureDetailTest
{
    #[Test]
    public function rejectsAnEmptyMessage(): void
    {
        expect()->calling(static fn(): FailureDetail => new FailureDetail(''))
            ->because('a failure detail MUST explain the failure')
            ->toThrow(
                \InvalidArgumentException::class,
                message: 'Failure detail message must not be empty.',
            );
    }

    #[Test]
    public function preservesAZeroStringMessageAcrossTheWire(): void
    {
        $detail = new FailureDetail('0');
        $restored = FailureDetail::fromWire(JsonWire::roundTrip($detail->toWire()));

        expect($restored->message)
            ->because('a zero-string failure message is not empty')
            ->toBe('0');
    }
}
