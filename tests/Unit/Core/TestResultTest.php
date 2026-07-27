<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Core;

use Greenlight\Attribute\Test;
use Greenlight\Core\Artifact\Attachment;
use Greenlight\Core\Artifact\AttachmentKind;
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
            attachments: [
                new Attachment(
                    'response.json',
                    AttachmentKind::Value,
                    'application/json',
                    2,
                    \str_repeat('a', 64),
                    1,
                    'build/artifacts/response.json',
                ),
            ],
        );

        $restored = TestResult::fromWire(JsonWire::roundTrip($result->toWire()));

        Expect::that($result->id->equals($restored->id))->because('survives the wire with full payload')->toBeTrue();
        Expect::that($restored->outcome)->because('survives the wire with full payload')->toBe(Outcome::Failed);
        Expect::that($restored->durationSeconds)->because('survives the wire with full payload')->toBe(0.125);
        Expect::that($restored->memoryDeltaBytes)->because('survives the wire with full payload')->toBe(2048);
        Expect::that($restored->attempts)->because('survives the wire with full payload')->toBe(2);
        Expect::that($restored->failures)->because('survives the wire with full payload')->toHaveCount(1);
        Expect::that($restored->failures[0]->message)->because('survives the wire with full payload')->toBe('expected 1, got 2');
        Expect::that((string) $restored->failures[0]->location)->because('survives the wire with full payload')->toBe('/app/tests/FooTest.php:12');
        Expect::that($restored->error?->class)->because('survives the wire with full payload')->toBe(\RuntimeException::class);
        Expect::that($restored->expectations)->because('survives the wire with full payload')->toBe(7);
        Expect::that($restored->attachments)->because('survives the wire with full payload')->toHaveCount(1);
        Expect::that($restored->attachments[0]->name)->because('survives the wire with full payload')->toBe('response.json');
    }

    #[Test]
    public function toleratesPayloadsWithoutNewOptionalFields(): void
    {
        $payload = new TestResult(new TestId('App\FooTest', 'bar'), Outcome::Passed, 0.1, 0)->toWire();
        unset($payload['expectations']);
        unset($payload['attachments']);

        $restored = TestResult::fromWire(JsonWire::roundTrip($payload));

        Expect::that($restored->expectations)->because('tolerates payloads without new optional fields')->toBe(0);
        Expect::that($restored->attachments)->because('tolerates payloads without new optional fields')->toBe([]);
    }

    #[Test]
    public function integerDurationSurvivesJson(): void
    {
        $result = new TestResult(new TestId('App\FooTest', 'bar'), Outcome::Passed, 1.0, 0);
        $restored = TestResult::fromWire(JsonWire::roundTrip($result->toWire()));

        Expect::that($restored->durationSeconds)->because('integer duration survives JSON')->toBe(1.0);
    }

    #[Test]
    public function withOutcomeRecordsProvenanceAndPreservesTheOriginal(): void
    {
        $original = new TestResult(new TestId('App\FooTest', 'bar'), Outcome::Failed, 0.1, 0, expectations: 3);
        $quarantined = $original->withOutcome(Outcome::Skipped, 'flaky-quarantine-plugin');

        Expect::that($quarantined->expectations)->because('with outcome records provenance and preserves the original')->toBe(3);

        Expect::that($original->outcome)->because('with outcome records provenance and preserves the original')->toBe(Outcome::Failed);
        Expect::that($original->transformations)->because('with outcome records provenance and preserves the original')->toBe([]);

        Expect::that($quarantined->outcome)->because('with outcome records provenance and preserves the original')->toBe(Outcome::Skipped);
        Expect::that($quarantined->transformations)->because('with outcome records provenance and preserves the original')->toHaveCount(1);
        Expect::that($quarantined->transformations[0]->transformedBy)->because('with outcome records provenance and preserves the original')->toBe('flaky-quarantine-plugin');
        Expect::that($quarantined->transformations[0]->from)->because('with outcome records provenance and preserves the original')->toBe(Outcome::Failed);
        Expect::that($quarantined->transformations[0]->to)->because('with outcome records provenance and preserves the original')->toBe(Outcome::Skipped);

        $restored = TestResult::fromWire(JsonWire::roundTrip($quarantined->toWire()));
        Expect::that($restored->transformations)->because('with outcome records provenance and preserves the original')->toHaveCount(1);
    }

    #[Test]
    public function rejectsInvalidConstruction(): void
    {
        $id = new TestId('App\FooTest', 'bar');

        Expect::that(static fn(): TestResult => new TestResult($id, Outcome::Passed, -0.1, 0))->because('rejects invalid construction')
            ->toThrow(\InvalidArgumentException::class);
        Expect::that(static fn(): TestResult => new TestResult($id, Outcome::Passed, 0.1, 0, 0))->because('rejects invalid construction')
            ->toThrow(\InvalidArgumentException::class);
    }

    #[Test]
    public function outcomeSuccessSemantics(): void
    {
        Expect::that(Outcome::Passed->isSuccessful())->because('outcome success uses the required semantics')->toBeTrue();
        Expect::that(Outcome::Skipped->isSuccessful())->because('outcome success uses the required semantics')->toBeTrue();
        Expect::that(Outcome::Failed->isSuccessful())->because('outcome success uses the required semantics')->toBeFalse();
        Expect::that(Outcome::Errored->isSuccessful())->because('outcome success uses the required semantics')->toBeFalse();
    }
}
