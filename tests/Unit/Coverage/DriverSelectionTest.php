<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Coverage;

use Greenlight\Attribute\Test;
use Greenlight\Coverage\Driver\DriverSelection;
use Greenlight\Expect\Expect;

final readonly class DriverSelectionTest
{
    #[Test]
    public function unavailableSelectionsRequireAReason(): void
    {
        Expect::that(static fn(): DriverSelection => DriverSelection::unavailable(''))
            ->because('coverage unavailability MUST explain why no driver was selected')
            ->toThrow(
                \InvalidArgumentException::class,
                message: 'Coverage unavailability requires a nonempty reason.',
            );
    }

    #[Test]
    public function unavailableSelectionsPreserveAZeroStringReason(): void
    {
        $selection = DriverSelection::unavailable('0');

        Expect::that($selection->reason)
            ->because('coverage unavailability MUST preserve a zero-string reason')
            ->toBe('0');
    }
}
