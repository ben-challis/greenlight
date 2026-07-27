<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Core;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Core\Result\Outcome;
use Greenlight\Core\Result\TestResult;
use Greenlight\Core\Test\TestId;
use Greenlight\Core\Wire\InvalidWirePayload;
use Greenlight\Expect\Expect;

final class TestResultWireTest
{
    #[Test]
    #[DataSet('boundedNumericFields')]
    public function outOfRangeWireValuesNormalizeToSafeBounds(string $field, int|float $wireValue, int|float $expected): void
    {
        $payload = $this->payload();
        $payload[$field] = $wireValue;

        Expect::that(TestResult::fromWire($payload)->toWire()[$field])
            ->because('out of range wire values normalize to safe bounds')
            ->toBe($expected);
    }

    #[Test]
    public function missingRiskyFlagUsesTheBackwardCompatibleDefault(): void
    {
        $payload = $this->payload();
        unset($payload['risky']);

        Expect::that(TestResult::fromWire($payload)->risky)
            ->because('a missing risky flag uses the backward compatible default')
            ->toBeFalse();
    }

    #[Test]
    public function invalidOutcomeNamesTheWireField(): void
    {
        $payload = $this->payload();
        $payload['outcome'] = 'unknown';

        Expect::that(static fn(): TestResult => TestResult::fromWire($payload))
            ->because('an invalid outcome names the wire field')
            ->toThrow(
                InvalidWirePayload::class,
                message: 'Wire payload key "outcome" must be a Greenlight\Core\Result\Outcome value, got string.',
            );
    }

    /**
     * @return iterable<string, array{non-empty-string, int|float, int|float}>
     */
    public static function boundedNumericFields(): iterable
    {
        yield 'duration' => ['durationSeconds', -0.25, 0.0];
        yield 'attempts' => ['attempts', 0, 1];
        yield 'expectations' => ['expectations', -1, 0];
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        return new TestResult(
            new TestId('App\ExampleTest', 'checksValue'),
            Outcome::Passed,
            0.1,
            0,
        )->toWire();
    }
}
