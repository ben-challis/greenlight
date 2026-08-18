<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Tempest;

use Greenlight\Attribute\SkipUnless;
use Greenlight\Attribute\Test;
use Greenlight\Condition\ClassAvailable;
use Greenlight\Expect\Expect;
use Greenlight\Tempest\TempestFrameworkRequirement;
use Tempest\Core\FrameworkKernel;
use Tempest\Core\Kernel;

#[SkipUnless(ClassAvailable::class, FrameworkKernel::class)]
final readonly class TempestFrameworkRequirementTest
{
    #[Test]
    public function installedFrameworkSatisfiesTheBridgeRequirement(): void
    {
        TempestFrameworkRequirement::check();

        Expect::that(Kernel::VERSION)
            ->because('the installed Tempest framework MUST satisfy the bridge requirement')
            ->toMatch('/^3\.(?:1[89]|[2-9][0-9]|[1-9][0-9]{2,})(?:\.|$)/D');
    }
}
