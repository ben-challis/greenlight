<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Coverage\Diff;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Coverage\Diff\FileDelta;
use Greenlight\Expect\Expect;

final readonly class FileDeltaMissingSideTest
{
    #[Test]
    #[DataSet('missingSides')]
    public function anAbsentSideContributesZero(
        ?float $baseline,
        ?float $current,
        float $expected,
    ): void {
        $delta = new FileDelta('/src/Example.php', $baseline, $current, []);

        Expect::that($delta->delta())
            ->because('an absent coverage-map side MUST contribute zero percent')
            ->toBe($expected);
    }

    /**
     * @return iterable<string, array{?float, ?float, float}>
     */
    public static function missingSides(): iterable
    {
        yield 'missing baseline' => [null, 75.0, 75.0];
        yield 'missing current' => [75.0, null, -75.0];
        yield 'both missing' => [null, null, 0.0];
    }
}
