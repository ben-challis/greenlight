<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Condition;

use Greenlight\Attribute\Test;
use Greenlight\Condition\EnvironmentVariableEquals;
use Greenlight\Condition\EnvironmentVariableSet;
use Greenlight\Expect\Expect;
use Greenlight\Fixture\EnvironmentSandbox;

final readonly class EnvironmentVariableAbsenceTest
{
    public function __construct(private EnvironmentSandbox $environment) {}

    #[Test]
    public function anAbsentVariableDoesNotEqualAnEmptyValue(): void
    {
        $name = 'GREENLIGHT_CONDITION_ABSENT_EMPTY_VALUE';
        $this->environment->unset($name);

        Expect::that(new EnvironmentVariableSet($name)->isSatisfied())
            ->because('an absent environment variable MUST remain absent')
            ->toBeFalse()
            ->and(new EnvironmentVariableEquals($name, '')->isSatisfied())
            ->because('absence MUST remain distinct from an empty environment variable value')
            ->toBeFalse();
    }
}
