<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Laravel;

use Greenlight\Attribute\SkipUnless;
use Greenlight\Attribute\Test;
use Greenlight\Condition\ClassAvailable;
use Greenlight\Expect\Expect;
use Greenlight\Laravel\LaravelFrameworkRequirement;
use Illuminate\Foundation\Application;

#[SkipUnless(ClassAvailable::class, Application::class)]
final readonly class LaravelFrameworkRequirementTest
{
    #[Test]
    public function installedFrameworkSatisfiesTheBridgeRequirement(): void
    {
        LaravelFrameworkRequirement::check();

        Expect::that(Application::VERSION)
            ->because('the installed Laravel framework MUST satisfy the bridge requirement')
            ->toMatch('/^13(?:\\.|$)/D');
    }
}
