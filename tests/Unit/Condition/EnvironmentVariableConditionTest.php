<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Condition;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Condition\EnvironmentVariableEquals;
use Greenlight\Condition\EnvironmentVariableSet;
use Greenlight\Sandbox\EnvironmentVariables;

use function Greenlight\expect;

final readonly class EnvironmentVariableConditionTest
{
    public function __construct(private EnvironmentVariables $environment) {}

    #[Test]
    #[DataSet('falseyValues')]
    public function falseyValuesRemainPresentAndCompareExactly(string $value): void
    {
        $name = 'GREENLIGHT_CONDITION_FALSEY_VALUE';
        $this->environment->set($name, $value);

        expect(new EnvironmentVariableSet($name)->isSatisfied())
            ->because('falsey values MUST remain distinct from missing environment variables')
            ->toBeTrue();
        expect(new EnvironmentVariableEquals($name, $value)->isSatisfied())
            ->because('environment variable comparisons MUST preserve the exact falsey value')
            ->toBeTrue();
        expect(new EnvironmentVariableEquals($name, 'different')->isSatisfied())
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
