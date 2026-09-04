<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Condition;

use Greenlight\Attribute\Test;
use Greenlight\Condition\OperatingSystemFamily;
use Greenlight\Expect\Expect;

final readonly class OperatingSystemFamilyValidationTest
{
    #[Test]
    public function rejectsAnEmptyFamily(): void
    {
        Expect::that(static fn(): OperatingSystemFamily => new OperatingSystemFamily('')) // @phpstan-ignore argument.type (deliberately invalid: tests runtime validation)
            ->because('an operating-system condition MUST identify the family')
            ->toThrow(
                \InvalidArgumentException::class,
                message: 'Operating system family cannot be empty.',
            );
    }
}
