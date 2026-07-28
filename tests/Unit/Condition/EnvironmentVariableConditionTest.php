<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Condition;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Condition\EnvironmentVariableEquals;
use Greenlight\Condition\EnvironmentVariableSet;
use Greenlight\Expect\Expect;
use Greenlight\Fixture\EnvironmentSandbox;

final readonly class EnvironmentVariableConditionTest
{
    public function __construct(private EnvironmentSandbox $environment) {}

    #[Test]
    #[DataSet('falseyValues')]
    public function falseyValuesRemainPresentAndCompareExactly(string $value): void
    {
        $name = 'GREENLIGHT_CONDITION_FALSEY_VALUE';
        $this->environment->set($name, $value);

        Expect::that(new EnvironmentVariableSet($name)->isSatisfied())
            ->because('falsey values MUST remain distinct from missing environment variables')
            ->toBeTrue()
            ->and(new EnvironmentVariableEquals($name, $value)->isSatisfied())
            ->because('environment variable comparisons MUST preserve the exact falsey value')
            ->toBeTrue()
            ->and(new EnvironmentVariableEquals($name, 'different')->isSatisfied())
            ->toBeFalse();
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function falseyValues(): iterable
    {
        yield 'empty string' => [''];
        yield 'zero string' => ['0'];
    }
}
