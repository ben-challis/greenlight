<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Expect;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;

use function Greenlight\expect;

final class NonFiniteToleranceSubjectTest
{
    #[Test]
    #[DataSet('nonFiniteSubjects')]
    public function aNonFiniteSubjectCannotBeWithinAFiniteTolerance(float $subject, float $delta, float $of): void
    {
        expect($subject)->not()->toBeWithin($delta, $of);
    }

    #[Test]
    public function finiteSubjectsStillMatchWhenAToleranceBoundaryOverflows(): void
    {
        expect(\PHP_FLOAT_MAX)->toBeWithin(\PHP_FLOAT_MAX, \PHP_FLOAT_MAX);
        expect(-\PHP_FLOAT_MAX)->toBeWithin(\PHP_FLOAT_MAX, -\PHP_FLOAT_MAX);
        expect(0.0)
            ->toBeWithin(\PHP_FLOAT_MAX, \PHP_FLOAT_MAX)
            ->toBeWithin(\PHP_FLOAT_MAX, -\PHP_FLOAT_MAX);
    }

    /** @return iterable<string, array{float, float, float}> */
    public static function nonFiniteSubjects(): iterable
    {
        yield 'upper boundary overflow' => [\INF, \PHP_FLOAT_MAX, \PHP_FLOAT_MAX];
        yield 'lower boundary overflow' => [-\INF, \PHP_FLOAT_MAX, -\PHP_FLOAT_MAX];
        yield 'positive infinity reference' => [\INF, 0.0, \INF];
        yield 'negative infinity reference' => [-\INF, 0.0, -\INF];
        yield 'not a number' => [\NAN, 0.0, 1.0];
    }
}
