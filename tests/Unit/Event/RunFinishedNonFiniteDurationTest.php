<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Event;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Event\RunFinished;
use Greenlight\Expect\Expect;
use Greenlight\Result\ResultSummary;

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
