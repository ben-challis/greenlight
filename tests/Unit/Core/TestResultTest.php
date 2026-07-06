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
use Greenlight\Tests\Support\Check;

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
        );

        $restored = TestResult::fromWire(Check::jsonRoundTrip($result->toWire()));

        Check::true($result->id->equals($restored->id), 'id to survive the wire');
        Check::same(Outcome::Failed, $restored->outcome, 'outcome');
        Check::same(0.125, $restored->durationSeconds, 'duration');
        Check::same(2048, $restored->memoryDeltaBytes, 'memory delta');
        Check::same(2, $restored->attempts, 'attempts');
        Check::same(1, \count($restored->failures), 'failure count');
        Check::same('expected 1, got 2', $restored->failures[0]->message, 'failure message');
        Check::same('/app/tests/FooTest.php:12', (string) $restored->failures[0]->location, 'failure location');
        Check::same(\RuntimeException::class, $restored->error?->class, 'error class');
    }

    #[Test]
    public function integerDurationSurvivesJson(): void
    {
        $result = new TestResult(new TestId('App\FooTest', 'bar'), Outcome::Passed, 1.0, 0);
        $restored = TestResult::fromWire(Check::jsonRoundTrip($result->toWire()));

        Check::same(1.0, $restored->durationSeconds, 'duration after JSON int collapse');
    }

    #[Test]
    public function withOutcomeRecordsProvenanceAndPreservesTheOriginal(): void
    {
        $original = new TestResult(new TestId('App\FooTest', 'bar'), Outcome::Failed, 0.1, 0);
        $quarantined = $original->withOutcome(Outcome::Skipped, 'flaky-quarantine-plugin');

        Check::same(Outcome::Failed, $original->outcome, 'original outcome untouched');
        Check::same([], $original->transformations, 'original transformation log untouched');

        Check::same(Outcome::Skipped, $quarantined->outcome, 'transformed outcome');
        Check::same(1, \count($quarantined->transformations), 'transformation log length');
        Check::same('flaky-quarantine-plugin', $quarantined->transformations[0]->transformedBy, 'provenance');
        Check::same(Outcome::Failed, $quarantined->transformations[0]->from, 'provenance from');
        Check::same(Outcome::Skipped, $quarantined->transformations[0]->to, 'provenance to');

        $restored = TestResult::fromWire(Check::jsonRoundTrip($quarantined->toWire()));
        Check::same(1, \count($restored->transformations), 'transformation log survives the wire');
    }

    #[Test]
    public function rejectsInvalidConstruction(): void
    {
        $id = new TestId('App\FooTest', 'bar');

        Check::throws(
            static fn(): TestResult => new TestResult($id, Outcome::Passed, -0.1, 0),
            \InvalidArgumentException::class,
            'negative duration',
        );
        Check::throws(
            static fn(): TestResult => new TestResult($id, Outcome::Passed, 0.1, 0, 0),
            \InvalidArgumentException::class,
            'zero attempts',
        );
    }

    #[Test]
    public function outcomeSuccessSemantics(): void
    {
        Check::true(Outcome::Passed->isSuccessful(), 'passed to be successful');
        Check::true(Outcome::Skipped->isSuccessful(), 'skipped to be successful');
        Check::true(!Outcome::Failed->isSuccessful(), 'failed to be unsuccessful');
        Check::true(!Outcome::Errored->isSuccessful(), 'errored to be unsuccessful');
    }
}
