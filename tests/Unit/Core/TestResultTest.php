<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Core;

use Greenlight\Attribute\Test;
use Greenlight\Core\Result\FailureDetail;
use Greenlight\Core\Result\Outcome;
use Greenlight\Core\Result\SourceLocation;
use Greenlight\Core\Result\TestResult;
use Greenlight\Core\Result\ThrowableDetail;
use Greenlight\Core\Test\TestId;
use Greenlight\Expect\Expect;
use Greenlight\Tests\Support\JsonWire;

final class TestResultTest
{
    #[Test]
    public function survivesTheWireWithFullPayload(): void
    {
        $result = new TestResult(
            new TestId('App\FooTest', 'bar', 'k'),
            Outcome::Failed,
            0.125,
            2048,
            2,
            [new FailureDetail('expected 1, got 2', '1', '2', new SourceLocation('/app/tests/FooTest.php', 12))],
            ThrowableDetail::fromThrowable(new \RuntimeException('boom')),
            expectations: 7,
        );

        $restored = TestResult::fromWire(JsonWire::roundTrip($result->toWire()));

        Expect::that($result->id->equals($restored->id))->toBeTrue();
        Expect::that($restored->outcome)->toBe(Outcome::Failed);
        Expect::that($restored->durationSeconds)->toBe(0.125);
        Expect::that($restored->memoryDeltaBytes)->toBe(2048);
        Expect::that($restored->attempts)->toBe(2);
        Expect::that($restored->failures)->toHaveCount(1);
        Expect::that($restored->failures[0]->message)->toBe('expected 1, got 2');
        Expect::that((string) $restored->failures[0]->location)->toBe('/app/tests/FooTest.php:12');
        Expect::that($restored->error?->class)->toBe(\RuntimeException::class);
        Expect::that($restored->expectations)->toBe(7);
    }

    #[Test]
    public function toleratesPayloadsWithoutExpectations(): void
    {
        $payload = new TestResult(new TestId('App\FooTest', 'bar'), Outcome::Passed, 0.1, 0)->toWire();
        unset($payload['expectations']);

        $restored = TestResult::fromWire(JsonWire::roundTrip($payload));

        Expect::that($restored->expectations)->toBe(0);
    }

    #[Test]
    public function integerDurationSurvivesJson(): void
    {
        $result = new TestResult(new TestId('App\FooTest', 'bar'), Outcome::Passed, 1.0, 0);
        $restored = TestResult::fromWire(JsonWire::roundTrip($result->toWire()));

        Expect::that($restored->durationSeconds)->toBe(1.0);
    }

    #[Test]
    public function withOutcomeRecordsProvenanceAndPreservesTheOriginal(): void
    {
        $original = new TestResult(new TestId('App\FooTest', 'bar'), Outcome::Failed, 0.1, 0, expectations: 3);
        $quarantined = $original->withOutcome(Outcome::Skipped, 'flaky-quarantine-plugin');

        Expect::that($quarantined->expectations)->toBe(3);

        Expect::that($original->outcome)->toBe(Outcome::Failed);
        Expect::that($original->transformations)->toBe([]);

        Expect::that($quarantined->outcome)->toBe(Outcome::Skipped);
        Expect::that($quarantined->transformations)->toHaveCount(1);
        Expect::that($quarantined->transformations[0]->transformedBy)->toBe('flaky-quarantine-plugin');
        Expect::that($quarantined->transformations[0]->from)->toBe(Outcome::Failed);
        Expect::that($quarantined->transformations[0]->to)->toBe(Outcome::Skipped);

        $restored = TestResult::fromWire(JsonWire::roundTrip($quarantined->toWire()));
        Expect::that($restored->transformations)->toHaveCount(1);
    }

    #[Test]
    public function rejectsInvalidConstruction(): void
    {
        $id = new TestId('App\FooTest', 'bar');

        Expect::that(static fn(): TestResult => new TestResult($id, Outcome::Passed, -0.1, 0))
            ->toThrow(\InvalidArgumentException::class);
        Expect::that(static fn(): TestResult => new TestResult($id, Outcome::Passed, 0.1, 0, 0))
            ->toThrow(\InvalidArgumentException::class);
    }

    #[Test]
    public function outcomeSuccessSemantics(): void
    {
        Expect::that(Outcome::Passed->isSuccessful())->toBeTrue();
        Expect::that(Outcome::Skipped->isSuccessful())->toBeTrue();
        Expect::that(Outcome::Failed->isSuccessful())->toBeFalse();
        Expect::that(Outcome::Errored->isSuccessful())->toBeFalse();
    }
}
