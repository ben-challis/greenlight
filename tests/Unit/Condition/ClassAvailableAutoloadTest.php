<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Condition;

use Greenlight\Attribute\Isolated;
use Greenlight\Attribute\Test;
use Greenlight\Condition\ClassAvailable;
use Greenlight\Tests\Fixture\Condition\AutoloadableConditionProbe;

use function Greenlight\expect;

final readonly class ClassAvailableAutoloadTest
{
    #[Test]
    #[Isolated]
    public function loadsAnAvailableClassBeforeReportingSuccess(): void
    {
        expect(\class_exists(AutoloadableConditionProbe::class, false))
            ->because('the fixture MUST start unloaded so the test covers autoloading')
            ->toBeFalse();
        expect(new ClassAvailable(AutoloadableConditionProbe::class)->isSatisfied())
            ->because('class availability MUST include classes that the autoloader can load')
            ->toBeTrue();
        expect(\class_exists(AutoloadableConditionProbe::class, false))
            ->because('the successful condition MUST load the available class')
            ->toBeTrue();
    }
}
