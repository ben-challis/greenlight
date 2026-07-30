<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Core;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Core\Event\RunFinished;
use Greenlight\Core\Result\ResultSummary;
use Greenlight\Expect\Expect;

final readonly class RunFinishedNonFiniteDurationTest
{
    #[Test]
    #[DataSet('nonFiniteDurations')]
    public function rejectsANonFiniteDuration(float $duration): void
    {
        Expect::that(static fn(): RunFinished => new RunFinished(
            'run-1',
            new ResultSummary(passed: 1),
            $duration,
            1.0,
        ))
            ->because('a run duration MUST remain representable on the wire')
            ->toThrow(
                \InvalidArgumentException::class,
                message: 'RunFinished duration is not finite.',
            );
    }

    /**
     * @return iterable<string, array{float}>
     */
    public static function nonFiniteDurations(): iterable
    {
        yield 'positive infinity' => [\INF];
        yield 'negative infinity' => [-\INF];
        yield 'not a number' => [\NAN];
    }
}
