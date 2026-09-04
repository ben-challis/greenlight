<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Tempest;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Tempest\TempestProcessState;

final readonly class TempestProcessEnvironmentValidationTest
{
    #[Test]
    public function rejectsAnEnvironmentWithANullByteBeforeFrameworkAccess(): void
    {
        Expect::that(static fn(): TempestProcessState => TempestProcessState::activate("test\0ing"))
            ->because('environment validation MUST run before Tempest framework access')
            ->toThrow(
                \InvalidArgumentException::class,
                message: 'Tempest environment cannot contain a null byte.',
            );
    }
}
