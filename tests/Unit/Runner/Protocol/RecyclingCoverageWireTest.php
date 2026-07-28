<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Runner\Protocol;

use Greenlight\Attribute\Test;
use Greenlight\Core\Event\RecycleReason;
use Greenlight\Core\Result\ResultSummary;
use Greenlight\Core\Test\TestId;
use Greenlight\Coverage\CoverageMap;
use Greenlight\Coverage\FileCoverage;
use Greenlight\Expect\Expect;
use Greenlight\Runner\Protocol\Messages\Recycling;

final class RecyclingCoverageWireTest
{
    #[Test]
    public function accumulatedCoverageSurvivesWorkerRecycling(): void
    {
        $coverage = new CoverageMap([
            new FileCoverage('/src/Example.php', [2, 5], [7, 11]),
        ]);
        $message = new Recycling(
            RecycleReason::Memory,
            [new TestId('App\ExampleTest', 'example')],
            new ResultSummary(passed: 1),
            $coverage,
        );

        $restored = Recycling::fromWire($message->toWire());

        Expect::that($restored->coverage?->toWire())
            ->because('recycled workers preserve accumulated coverage for the orchestrator')
            ->toBe($coverage->toWire());
    }
}
