<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Laravel;

use Greenlight\Attribute\SkipUnless;
use Greenlight\Attribute\Test;
use Greenlight\Condition\ClassAvailable;
use Greenlight\Laravel\LaravelFrameworkRequirement;
use Illuminate\Foundation\Application;

use function Greenlight\expect;

#[SkipUnless(ClassAvailable::class, Application::class)]
final readonly class LaravelFrameworkRequirementTest
{
    #[Test]
    public function installedFrameworkSatisfiesTheBridgeRequirement(): void
    {
        LaravelFrameworkRequirement::check();

        expect(Application::VERSION)
            ->because('the installed Laravel framework MUST satisfy the bridge requirement')
            ->toMatch('/^13(?:\\.|$)/D');
    }
}
