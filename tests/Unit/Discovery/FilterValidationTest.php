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
        Expect::that(static fn(): Filter => self::withEmptyValue($dimension))
            ->because('an empty filter value MUST NOT broaden or suppress test selection')
            ->toThrow(
                \InvalidArgumentException::class,
                message: \sprintf(
                    'Filter "%s" MUST contain only non-empty strings.',
                    $dimension,
                ),
            );
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

    private static function withEmptyValue(string $dimension): Filter
    {
        return match ($dimension) {
            'includeGroups' => new Filter(includeGroups: ['']),
            'excludeGroups' => new Filter(excludeGroups: ['']),
            'includeClasses' => new Filter(includeClasses: ['']),
            'excludeClasses' => new Filter(excludeClasses: ['']),
            'includeMethods' => new Filter(includeMethods: ['']),
            'excludeMethods' => new Filter(excludeMethods: ['']),
            'includePaths' => new Filter(includePaths: ['']),
            'excludePaths' => new Filter(excludePaths: ['']),
            'includeIds' => new Filter(includeIds: ['']),
            'includeExactIds' => new Filter(includeExactIds: ['']),
            default => throw new \LogicException(\sprintf('Unknown filter dimension "%s".', $dimension)),
        };
    }
}
