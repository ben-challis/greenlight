<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Reporting;

use Greenlight\Attribute\Test;
use Greenlight\Core\Event\TestFinished;
use Greenlight\Core\Result\Outcome;
use Greenlight\Core\Result\TestResult;
use Greenlight\Core\Test\TestId;
use Greenlight\Expect\Expect;
use Greenlight\Reporting\SlowTests;
use Greenlight\Reporting\Style;

final class SlowTestsThresholdTest
{
    #[Test]
    public function rendersOnlyTestsAboveTheHalfSecondThreshold(): void
    {
        $slow = new SlowTests();
        $slow->record($this->finished('exactlyAtThreshold', 0.500));
        $slow->record($this->finished('aboveThreshold', 0.501));

        Expect::that($slow->render(new Style(ansi: false)))
            ->because('the slow-test block MUST contain only tests above the half-second threshold')
            ->toBe("\nSlowest tests:\n  0.501s Acme\\SlowTest::aboveThreshold\n");
    }

    /**
     * @param non-empty-string $method
     */
    private function finished(string $method, float $duration): TestFinished
    {
        return new TestFinished(
            new TestResult(
                new TestId('Acme\\SlowTest', $method),
                Outcome::Passed,
                $duration,
                0,
            ),
            1.0,
        );
    }
}
