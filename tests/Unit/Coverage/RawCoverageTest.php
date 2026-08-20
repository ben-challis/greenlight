<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Coverage;

use Greenlight\Attribute\Test;
use Greenlight\Coverage\RawCoverage;
use Greenlight\Expect\Expect;

final readonly class RawCoverageTest
{
    #[Test]
    public function malformedDriverEntriesAreDiscarded(): void
    {
        $coverage = new RawCoverage([
            '/valid.php' => [
                10 => 1,
                11 => -1,
                'line' => 1,
                12 => 'covered',
            ],
            7 => [20 => 1],
            '/invalid.php' => 'not line coverage',
        ]);

        Expect::that($coverage->lines)
            ->because('raw coverage MUST keep only integer statuses keyed by integer lines')
            ->toBe([
                '/valid.php' => [
                    10 => 1,
                    11 => -1,
                ],
            ]);
    }
}
