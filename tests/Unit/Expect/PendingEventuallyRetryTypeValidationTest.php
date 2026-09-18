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
        Expect::calling(static function (): void {
            new \ReflectionMethod(PendingEventually::class, 'retryOnException')
                ->invoke(Expect::calling(static fn(): int => 1)->returnValue()->eventually(), \Error::class);
        })
            ->because('an invalid retry type MUST identify the type and the Exception requirement')
            ->toThrow(
                \InvalidArgumentException::class,
                message: 'Retry exception type "Error" must extend Exception.',
            );
    }
}
