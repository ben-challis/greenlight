<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Discovery;

use Greenlight\Attribute\Test;
use Greenlight\Core\Test\TestId;
use Greenlight\Core\Test\TestMetadata;
use Greenlight\Discovery\PlanEntry;
use Greenlight\Expect\Expect;

final readonly class PlanEntryIdentityTest
{
    #[Test]
    public function mismatchedIdentityNamesBothSides(): void
    {
        Expect::that(static fn(): PlanEntry => new PlanEntry(
            new TestId('App\PaymentTest', 'chargesCard'),
            new TestMetadata('App\RefundTest', 'refundsCard'),
        ))
            ->because('a plan identity mismatch MUST identify the test ID and its metadata')
            ->toThrow(
                \InvalidArgumentException::class,
                message: 'Plan entry identity App\PaymentTest::chargesCard does not match its metadata App\RefundTest::refundsCard.',
            );
    }
}
