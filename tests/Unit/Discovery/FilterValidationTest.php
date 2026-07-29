<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Discovery;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Discovery\Filter;
use Greenlight\Expect\Expect;

final class FilterValidationTest
{
    #[Test]
    #[DataSet('filterDimensions')]
    public function emptyFilterValuesAreRejected(string $dimension): void
    {
        Expect::that(static fn(): Filter => self::withValue($dimension, ''))
            ->because('an empty filter value MUST NOT broaden or suppress test selection')
            ->toThrow(
                \InvalidArgumentException::class,
                message: \sprintf(
                    'Filter "%s" MUST contain only non-empty strings.',
                    $dimension,
                ),
            );
    }

    #[Test]
    #[DataSet('filterDimensions')]
    public function zeroFilterValuesAreRetained(string $dimension): void
    {
        $filter = self::withValue($dimension, '0');

        Expect::that(\get_object_vars($filter)[$dimension] ?? null)
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

    private static function withValue(string $dimension, string $value): Filter
    {
        return match ($dimension) {
            'includeGroups' => new Filter(includeGroups: [$value]),
            'excludeGroups' => new Filter(excludeGroups: [$value]),
            'includeClasses' => new Filter(includeClasses: [$value]),
            'excludeClasses' => new Filter(excludeClasses: [$value]),
            'includeMethods' => new Filter(includeMethods: [$value]),
            'excludeMethods' => new Filter(excludeMethods: [$value]),
            'includePaths' => new Filter(includePaths: [$value]),
            'excludePaths' => new Filter(excludePaths: [$value]),
            'includeIds' => new Filter(includeIds: [$value]),
            'includeExactIds' => new Filter(includeExactIds: [$value]),
            default => throw new \LogicException(\sprintf('Unknown filter dimension "%s".', $dimension)),
        };
    }
}
