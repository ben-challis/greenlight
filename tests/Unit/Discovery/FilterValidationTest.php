<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Discovery;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Test\TestExclusions;
use Greenlight\Test\TestInclusions;

final class FilterValidationTest
{
    #[Test]
    #[DataSet('filterDimensions')]
    public function emptyFilterValuesAreRejected(string $dimension): void
    {
        Expect::that(static fn(): TestInclusions|TestExclusions => self::withValue($dimension, ''))
            ->because('an empty filter value MUST NOT broaden or suppress test selection')
            ->toThrow(
                \InvalidArgumentException::class,
                message: \sprintf(
                    '%s cannot contain an empty string.',
                    $this->propertyName($dimension),
                ),
            );
    }

    #[Test]
    #[DataSet('filterDimensions')]
    public function zeroFilterValuesAreRetained(string $dimension): void
    {
        $filter = self::withValue($dimension, '0');

        $property = $this->propertyName($dimension);

        Expect::that(\get_object_vars($filter)[$property] ?? null)
            ->because('a non-empty falsey filter value MUST retain its selection meaning')
            ->toBe(['0']);
    }

    /**
     * @return iterable<string, array{non-empty-string}>
     */
    public static function filterDimensions(): iterable
    {
        yield 'included group' => ['includeGroups'];
        yield 'excluded group' => ['excludeGroups'];
        yield 'included class' => ['includeClasses'];
        yield 'excluded class' => ['excludeClasses'];
        yield 'included method' => ['includeMethods'];
        yield 'excluded method' => ['excludeMethods'];
        yield 'included path' => ['includePaths'];
        yield 'excluded path' => ['excludePaths'];
        yield 'included test ID' => ['includeIds'];
        yield 'included exact test ID' => ['includeExactIds'];
    }

    private static function withValue(string $dimension, string $value): TestInclusions|TestExclusions
    {
        return match ($dimension) {
            'includeGroups' => new TestInclusions(groups: [$value]),
            'excludeGroups' => new TestExclusions(groups: [$value]),
            'includeClasses' => new TestInclusions(classes: [$value]),
            'excludeClasses' => new TestExclusions(classes: [$value]),
            'includeMethods' => new TestInclusions(methods: [$value]),
            'excludeMethods' => new TestExclusions(methods: [$value]),
            'includePaths' => new TestInclusions(paths: [$value]),
            'excludePaths' => new TestExclusions(paths: [$value]),
            'includeIds' => new TestInclusions(idPatterns: [$value]),
            'includeExactIds' => new TestInclusions(exactIds: [$value]),
            default => throw new \LogicException(\sprintf('Unknown filter dimension "%s".', $dimension)),
        };
    }

    private function propertyName(string $dimension): string
    {
        return match ($dimension) {
            'includeGroups', 'excludeGroups' => 'groups',
            'includeClasses', 'excludeClasses' => 'classes',
            'includeMethods', 'excludeMethods' => 'methods',
            'includePaths', 'excludePaths' => 'paths',
            'includeIds' => 'idPatterns',
            'includeExactIds' => 'exactIds',
            default => throw new \LogicException(\sprintf('Unknown filter dimension "%s".', $dimension)),
        };
    }
}
