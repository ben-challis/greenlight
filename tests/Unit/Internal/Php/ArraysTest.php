<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Internal\Php;

use Greenlight\Attribute\Test;
use Greenlight\Internal\Php\Arrays;

use function Greenlight\expect;

final class ArraysTest
{
    #[Test]
    public function anyStopsAtTheFirstMatch(): void
    {
        $visited = [];
        $found = Arrays::any(['a' => 1, 'b' => 2, 'c' => 3], static function (int $value, string $key) use (&$visited): bool {
            $visited[] = $key;

            return $value === 2;
        });

        expect($found)->because('any() MUST return true when a value matches')->toBeTrue();
        expect($visited)->because('any() MUST stop at the first match and pass each key')->toBe(['a', 'b']);
    }

    #[Test]
    public function anyIsFalseWithoutAMatch(): void
    {
        expect(Arrays::any([1, 2], static fn(int $value): bool => $value > 2))
            ->because('any() MUST return false when no value matches')
            ->toBeFalse();
        expect(Arrays::any([], static fn(): bool => true))
            ->because('any() MUST return false for an empty array')
            ->toBeFalse();
    }

    #[Test]
    public function allStopsAtTheFirstMismatch(): void
    {
        $visited = [];
        $matches = Arrays::all(['a' => 1, 'b' => 2, 'c' => 3], static function (int $value, string $key) use (&$visited): bool {
            $visited[] = $key;

            return $value < 2;
        });

        expect($matches)->because('all() MUST return false when a value does not match')->toBeFalse();
        expect($visited)->because('all() MUST stop at the first mismatch and pass each key')->toBe(['a', 'b']);
    }

    #[Test]
    public function allIsTrueWhenEveryValueMatches(): void
    {
        expect(Arrays::all([1, 2], static fn(int $value): bool => $value > 0))
            ->because('all() MUST return true when every value matches')
            ->toBeTrue();
        expect(Arrays::all([], static fn(): bool => false))
            ->because('all() MUST return true for an empty array')
            ->toBeTrue();
    }

    #[Test]
    public function findReturnsTheFirstMatchingValueOrNull(): void
    {
        expect(Arrays::find(['a' => 1, 'b' => 2, 'c' => 4], static fn(int $value): bool => $value % 2 === 0))
            ->because('find() MUST return the first matching value')
            ->toBe(2);
        expect(Arrays::find(['a' => 1], static fn(int $value, string $key): bool => $key === 'b'))
            ->because('find() MUST return null when no value matches')
            ->toBeNull();
    }
}
