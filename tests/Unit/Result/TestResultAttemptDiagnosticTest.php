<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Result;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Result\Outcome;
use Greenlight\Result\TestResult;
use Greenlight\Test\TestId;

final readonly class TestResultAttemptDiagnosticTest
{
    #[Test]
    public function invalidAttemptCountNamesTheMinimum(): void
    {
        Expect::that(static fn(): TestResult => new TestResult(
            new TestId('App\PaymentTest', 'chargesCard'),
            Outcome::Passed,
            0.1,
            0,
            0,
        ))
            ->because('an invalid result attempt count MUST name the valid minimum')
            ->toThrow(
                \InvalidArgumentException::class,
                message: 'Attempts must be at least 1.',
            );
    }
}
