<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Cli\Watch;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Cli\Watch\Debouncer;

use function Greenlight\expect;

final readonly class DebouncerValidationTest
{
    #[Test]
    #[DataSet('nonFiniteQuietPeriods')]
    public function rejectsANonFiniteQuietPeriod(float $quietSeconds): void
    {
        expect()->calling(static fn(): Debouncer => new Debouncer($quietSeconds))
            ->because('a non-finite quiet period MUST NOT disable future watch runs')
            ->toThrow(
                \InvalidArgumentException::class,
                message: 'Set the quiet period to zero seconds or more.',
            );
    }

    /**
     * @return iterable<string, array{float}>
     */
    public static function nonFiniteQuietPeriods(): iterable
    {
        yield 'not a number' => [\NAN];
        yield 'positive infinity' => [\INF];
        yield 'negative infinity' => [-\INF];
    }
}
