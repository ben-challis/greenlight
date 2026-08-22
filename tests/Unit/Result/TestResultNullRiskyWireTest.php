<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Result;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Internal\Wire\InvalidWirePayload;
use Greenlight\Result\Outcome;
use Greenlight\Result\TestResult;
use Greenlight\Test\TestId;

final readonly class TestResultNullRiskyWireTest
{
    #[Test]
    public function explicitNullRiskyFlagIsRejected(): void
    {
        $payload = new TestResult(
            new TestId('App\ExampleTest', 'passes'),
            Outcome::Passed,
            0.1,
            0,
        )->toWire();
        $payload['risky'] = null;

        Expect::that(static fn(): TestResult => TestResult::fromWire($payload))
            ->because('an explicit null risky flag MUST NOT use the missing-field default')
            ->toThrow(
                InvalidWirePayload::class,
                message: 'Wire payload key "risky" must be a boolean, got null.',
            );
    }
}
