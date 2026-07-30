<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Core;

use Greenlight\Attribute\Test;
use Greenlight\Core\Result\Outcome;
use Greenlight\Core\Result\TestResult;
use Greenlight\Core\Test\TestId;
use Greenlight\Core\Wire\InvalidWirePayload;
use Greenlight\Expect\Expect;

final readonly class TestResultNullExpectationsWireTest
{
    #[Test]
    public function explicitNullExpectationCountIsRejected(): void
    {
        $payload = new TestResult(
            new TestId('App\ExampleTest', 'passes'),
            Outcome::Passed,
            0.1,
            0,
        )->toWire();
        $payload['expectations'] = null;

        Expect::that(static fn(): TestResult => TestResult::fromWire($payload))
            ->because('an explicit null expectation count MUST NOT use the missing-field default')
            ->toThrow(
                InvalidWirePayload::class,
                message: 'Wire payload key "expectations" must be an integer, got null.',
            );
    }
}
