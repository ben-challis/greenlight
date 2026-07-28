<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Coverage;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Coverage\FileCoverage;
use Greenlight\Expect\Expect;

final class FileCoverageLineValidationTest
{
    /**
     * @param list<int> $covered
     * @param list<int> $uncovered
     */
    #[Test]
    #[DataSet('nonPositiveLines')]
    public function rejectsNonPositiveLines(
        array $covered,
        array $uncovered,
        int $line,
    ): void {
        Expect::that(
            static fn(): FileCoverage => new FileCoverage(
                '/src/A.php',
                $covered,
                $uncovered,
            ),
        )
            ->because('coverage line numbers MUST be positive')
            ->toThrow(
                \InvalidArgumentException::class,
                message: \sprintf(
                    'Use positive coverage line numbers. Actual value: %d.',
                    $line,
                ),
            );
    }

    /**
     * @return iterable<string, array{list<int>, list<int>, int}>
     */
    public static function nonPositiveLines(): iterable
    {
        yield 'zero covered line' => [[0], [], 0];
        yield 'negative covered line' => [[-1], [], -1];
        yield 'zero uncovered line' => [[], [0], 0];
        yield 'negative uncovered line' => [[], [-1], -1];
    }
}
