<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Expect;

use Greenlight\Attribute\Test;
use Greenlight\Expect\PendingEventually;

use function Greenlight\expect;

final class PendingEventuallyRetryTypeValidationTest
{
    #[Test]
    public function invalidRetryTypesIdentifyTheTypeAndRequirement(): void
    {
        expect()->calling(static function (): void {
            new \ReflectionMethod(PendingEventually::class, 'retryOnException')
                ->invoke(expect()->calling(static fn(): int => 1)->returnValue()->eventually(), \Error::class);
        })
            ->because('an invalid retry type MUST identify the type and the Exception requirement')
            ->toThrow(
                \InvalidArgumentException::class,
                message: 'Retry exception type "Error" must extend Exception.',
            );
    }
}
