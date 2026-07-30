<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Expect;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Expect\PendingEventually;

final class PendingEventuallyRetryTypeValidationTest
{
    #[Test]
    public function invalidRetryTypesIdentifyTheTypeAndRequirement(): void
    {
        Expect::that(static function (): void {
            new \ReflectionMethod(PendingEventually::class, 'retryOnException')
                ->invoke(Expect::eventually(static fn(): int => 1), \Error::class);
        })
            ->because('an invalid retry type MUST identify the type and the Exception requirement')
            ->toThrow(
                \InvalidArgumentException::class,
                message: 'Retry exception type "Error" must extend Exception.',
            );
    }
}
