<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Result;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Result\FailureDetail;
use Greenlight\Tests\Support\JsonWire;

final readonly class FailureDetailTest
{
    #[Test]
    public function rejectsAnEmptyMessage(): void
    {
        Expect::that(static fn(): FailureDetail => new FailureDetail(''))
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

        Expect::that($restored->message)
            ->because('a zero-string failure message is not empty')
            ->toBe('0');
    }
}
