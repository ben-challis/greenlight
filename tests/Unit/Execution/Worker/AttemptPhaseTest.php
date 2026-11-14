<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Execution\Worker;

use Greenlight\Attribute\Test;
use Greenlight\Execution\Worker\AttemptPhase;

use function Greenlight\expect;

final class AttemptPhaseTest
{
    #[Test]
    public function testBodyPhasesPrecedeTeardownPhases(): void
    {
        $names = static fn(array $phases): array => \array_map(
            static fn(AttemptPhase $phase): string => $phase->name,
            \array_values($phases),
        );
        $body = \array_filter(AttemptPhase::cases(), static fn(AttemptPhase $phase): bool => !$phase->alwaysRuns());
        $teardown = \array_filter(AttemptPhase::cases(), static fn(AttemptPhase $phase): bool => $phase->alwaysRuns());

        expect($names($body))
            ->because('only the test body stops at the first skip, failure, or error')
            ->toBe(['Instantiate', 'BeforeTestSubscribers', 'TestMethod']);
        expect($names($teardown))
            ->because('teardown phases always run, in this order')
            ->toBe(['AfterHooks', 'CleanupCallbacks', 'CloseTestScope', 'LeaveExpectationWindow', 'StopCapture']);
    }

    #[Test]
    public function onlyTheCaptureStopIsOutsideTheWindow(): void
    {
        $outside = \array_values(\array_filter(
            AttemptPhase::cases(),
            static fn(AttemptPhase $phase): bool => !$phase->insideWindow(),
        ));

        expect($outside)
            ->because('cleanup and test-scope disposal MUST stay inside the capture window')
            ->toBe([AttemptPhase::StopCapture]);
    }
}
