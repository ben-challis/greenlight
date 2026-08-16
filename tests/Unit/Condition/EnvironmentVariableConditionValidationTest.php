<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Condition;

use Greenlight\Attribute\Test;
use Greenlight\Condition\EnvironmentVariableEquals;
use Greenlight\Condition\EnvironmentVariableSet;
use Greenlight\Expect\Expect;

final readonly class EnvironmentVariableConditionValidationTest
{
    #[Test]
    public function rejectsAnEmptyEnvironmentVariableName(): void
    {
        Expect::that(static fn(): EnvironmentVariableSet => new EnvironmentVariableSet(''))
            ->because('a presence condition MUST identify the environment variable')
            ->toThrow(
                \InvalidArgumentException::class,
                message: 'Environment variable name MUST NOT be empty.',
            );
        Expect::that(static fn(): EnvironmentVariableEquals => new EnvironmentVariableEquals('', 'value'))
            ->because('an equality condition MUST identify the environment variable')
            ->toThrow(
                \InvalidArgumentException::class,
                message: 'Environment variable name MUST NOT be empty.',
            );
    }
}
