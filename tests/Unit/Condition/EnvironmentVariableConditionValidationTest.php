<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Condition;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Condition\EnvironmentVariableEquals;
use Greenlight\Condition\EnvironmentVariableSet;
use Greenlight\Expect\Expect;

final readonly class EnvironmentVariableConditionValidationTest
{
    #[Test]
    #[DataSet('invalidNames')]
    public function rejectsAnInvalidEnvironmentVariableName(string $name): void
    {
        Expect::that(static fn(): EnvironmentVariableSet => new EnvironmentVariableSet($name)) // @phpstan-ignore argument.type (deliberately invalid: tests runtime validation)
            ->because('a presence condition MUST reject a name that getenv() cannot safely read')
            ->toThrow(
                \InvalidArgumentException::class,
                message: 'Environment variable names cannot be empty or contain "=" or a null byte.',
            );
        Expect::that(static fn(): EnvironmentVariableEquals => new EnvironmentVariableEquals($name, 'value')) // @phpstan-ignore argument.type (deliberately invalid: tests runtime validation)
            ->because('an equality condition MUST reject a name that getenv() cannot safely read')
            ->toThrow(
                \InvalidArgumentException::class,
                message: 'Environment variable names cannot be empty or contain "=" or a null byte.',
            );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidNames(): iterable
    {
        yield 'empty' => [''];
        yield 'assignment delimiter' => ['NAME=value'];
        yield 'null byte' => ["NAME\0suffix"];
    }
}
