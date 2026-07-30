<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Core;

use Greenlight\Attribute\Test;
use Greenlight\Core\Result\Outcome;
use Greenlight\Core\Result\TestResult;
use Greenlight\Core\Test\TestId;
use Greenlight\Expect\Expect;

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
