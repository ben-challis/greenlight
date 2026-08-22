<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Runner\Protocol;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Result\ResultSummary;
use Greenlight\Runner\Protocol\Messages\Done;

final readonly class DoneResultPayloadWireTest
{
    #[Test]
    public function accumulatedCoverageAndLeaksSurviveTheWire(): void
    {
        $payload = [
            'summary' => new ResultSummary(passed: 1)->toWire(),
            'peakMemoryBytes' => 4096,
            'coverage' => [
                'files' => [
                    '/project/src/Example.php' => [[10, 12], [11]],
                ],
            ],
            'leaks' => [[
                'class' => 'App\LeakyTest',
                'method' => 'retainsItself',
                'dataSetKey' => 'first',
            ]],
        ];

        $done = Done::fromWire($payload);

        Expect::that($done->toWire())
            ->because('a completed assignment MUST preserve its coverage and leaked test IDs')
            ->toBe($payload);
    }
}
