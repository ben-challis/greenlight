<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Result;

use Greenlight\Artifact\Attachment;
use Greenlight\Artifact\AttachmentKind;
use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Result\CapturedOutput;
use Greenlight\Result\Diagnostic;
use Greenlight\Result\DiagnosticSeverity;
use Greenlight\Result\FailureDetail;
use Greenlight\Result\Outcome;
use Greenlight\Result\OutcomeTransformation;
use Greenlight\Result\SourceLocation;
use Greenlight\Result\TestResult;
use Greenlight\Result\ThrowableDetail;
use Greenlight\Test\TestId;
use Greenlight\Tests\Support\JsonWire;

use function Greenlight\expect;

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
            skipReason: 'known issue',
            transformations: [
                new OutcomeTransformation('quarantine-plugin', Outcome::Passed, Outcome::Skipped),
                new OutcomeTransformation('result-policy', Outcome::Skipped, Outcome::Failed),
            ],
            output: new CapturedOutput(
                "first line\nsecond line",
                [
                    new Diagnostic(
                        DiagnosticSeverity::Warning,
                        'deprecated call',
                        '/app/src/Foo.php',
                        21,
                    ),
                ],
                stdoutTruncated: true,
                diagnosticsTruncated: true,
            ),
            risky: true,
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

        expect($result->id->equals($restored->id))->because('survives the wire with full payload')->toBeTrue();
        expect($restored->outcome)->because('survives the wire with full payload')->toBe(Outcome::Failed);
        expect($restored->durationSeconds)->because('survives the wire with full payload')->toBe(0.125);
        expect($restored->memoryDeltaBytes)->because('survives the wire with full payload')->toBe(2048);
        expect($restored->attempts)->because('survives the wire with full payload')->toBe(2);
        expect($restored->failures)->because('survives the wire with full payload')->toHaveCount(1);
        expect($restored->failures[0]->message)->because('survives the wire with full payload')->toBe('expected 1, got 2');
        expect((string) $restored->failures[0]->location)->because('survives the wire with full payload')->toBe('/app/tests/FooTest.php:12');
        expect($restored->error?->class)->because('survives the wire with full payload')->toBe(\RuntimeException::class);
        expect($restored->skipReason)->because('survives the wire with full payload')->toBe('known issue');
        expect(\array_map(
            static fn(OutcomeTransformation $transformation): array => [
                $transformation->transformedBy,
                $transformation->from,
                $transformation->to,
            ],
            $restored->transformations,
        ))
            ->because('transformation provenance and order survive the wire')
            ->toBe([
                ['quarantine-plugin', Outcome::Passed, Outcome::Skipped],
                ['result-policy', Outcome::Skipped, Outcome::Failed],
            ]);
        expect($restored->output)
            ->because('captured output and truncation state survive the wire')
            ->not()
            ->toBeNull();
        expect($restored->output->stdout)
            ->because('captured output and truncation state survive the wire')
            ->toBe("first line\nsecond line");
        expect($restored->output->diagnostics[0]->severity)
            ->toBe(DiagnosticSeverity::Warning);
        expect($restored->output->diagnostics[0]->message)
            ->toBe('deprecated call');
        expect($restored->output->diagnostics[0]->file)
            ->toBe('/app/src/Foo.php');
        expect($restored->output->diagnostics[0]->line)
            ->toBe(21);
        expect($restored->output->stdoutTruncated)
            ->toBeTrue();
        expect($restored->output->diagnosticsTruncated)
            ->toBeTrue();
        expect($restored->risky)->because('survives the wire with full payload')->toBeTrue();
        expect($restored->expectations)->because('survives the wire with full payload')->toBe(7);
        expect($restored->attachments)->because('survives the wire with full payload')->toHaveCount(1);
        expect($restored->attachments[0]->name)->because('survives the wire with full payload')->toBe('response.json');
    }

    #[Test]
    public function toleratesPayloadsWithoutNewOptionalFields(): void
    {
        $payload = new TestResult(new TestId('App\FooTest', 'bar'), Outcome::Passed, 0.1, 0)->toWire();
        unset($payload['expectations']);
        unset($payload['attachments']);

        $restored = TestResult::fromWire(JsonWire::roundTrip($payload));

        expect($restored->expectations)->because('tolerates payloads without new optional fields')->toBe(0);
        expect($restored->attachments)->because('tolerates payloads without new optional fields')->toBe([]);
    }

    #[Test]
    public function integerDurationSurvivesJson(): void
    {
        $result = new TestResult(new TestId('App\FooTest', 'bar'), Outcome::Passed, 1.0, 0);
        $restored = TestResult::fromWire(JsonWire::roundTrip($result->toWire()));

        expect($restored->durationSeconds)->because('integer duration survives JSON')->toBe(1.0);
    }

    #[Test]
    public function withOutcomeRecordsProvenanceAndPreservesTheOriginal(): void
    {
        $original = new TestResult(new TestId('App\FooTest', 'bar'), Outcome::Failed, 0.1, 0, expectations: 3);
        $quarantined = $original->withOutcome(Outcome::Skipped, 'flaky-quarantine-plugin');

        expect($quarantined->expectations)->because('with outcome records provenance and preserves the original')->toBe(3);

        expect($original->outcome)->because('with outcome records provenance and preserves the original')->toBe(Outcome::Failed);
        expect($original->transformations)->because('with outcome records provenance and preserves the original')->toBe([]);

        expect($quarantined->outcome)->because('with outcome records provenance and preserves the original')->toBe(Outcome::Skipped);
        expect($quarantined->transformations)->because('with outcome records provenance and preserves the original')->toHaveCount(1);
        expect($quarantined->transformations[0]->transformedBy)->because('with outcome records provenance and preserves the original')->toBe('flaky-quarantine-plugin');
        expect($quarantined->transformations[0]->from)->because('with outcome records provenance and preserves the original')->toBe(Outcome::Failed);
        expect($quarantined->transformations[0]->to)->because('with outcome records provenance and preserves the original')->toBe(Outcome::Skipped);

        $restored = TestResult::fromWire(JsonWire::roundTrip($quarantined->toWire()));
        expect($restored->transformations)->because('with outcome records provenance and preserves the original')->toHaveCount(1);
    }

    #[Test]
    public function withOutcomeRejectsAnEmptyTransformationSource(): void
    {
        $result = new TestResult(new TestId('App\FooTest', 'bar'), Outcome::Failed, 0.1, 0);

        expect()->calling(static fn(): TestResult => $result->withOutcome(Outcome::Skipped, '')) // @phpstan-ignore argument.type (deliberately invalid: tests runtime validation)
            ->because('an outcome transformation MUST identify its source')
            ->toThrow(
                \InvalidArgumentException::class,
                message: 'Outcome transformation source must not be empty.',
            );
    }

    #[Test]
    public function errorTransitionPreservesEarlierFailureEvidence(): void
    {
        $failure = new FailureDetail('The assertion failed.', 'ready', 'waiting');
        $original = new TestResult(
            new TestId('App\FooTest', 'bar'),
            Outcome::Failed,
            0.25,
            1024,
            attempts: 2,
            failures: [$failure],
            expectations: 3,
        );
        $error = new ThrowableDetail(
            \RuntimeException::class,
            'Teardown failed.',
            '/app/tests/FooTest.php',
            42,
        );

        $errored = $original->erroredBy($error);

        expect($errored->outcome)
            ->because('a later lifecycle error MUST replace the final outcome')
            ->toBe(Outcome::Errored);
        expect($errored->error)
            ->because('the lifecycle error MUST remain available for diagnostics')
            ->toBe($error);
        expect($errored->failures)
            ->because('a later lifecycle error MUST NOT discard earlier failure evidence')
            ->toBe([$failure]);
        expect($errored->attempts)
            ->because('a later lifecycle error MUST preserve the attempt count')
            ->toBe(2);
        expect($errored->expectations)
            ->because('a later lifecycle error MUST preserve the expectation count')
            ->toBe(3);
    }

    #[Test]
    public function rejectsInvalidAttemptCounts(): void
    {
        $id = new TestId('App\FooTest', 'bar');

        expect()->calling(static fn(): TestResult => new TestResult($id, Outcome::Passed, 0.1, 0, 0))
            ->because('attempt counts MUST start at one')
            ->toThrow(\InvalidArgumentException::class);
    }

    #[Test]
    #[DataSet('invalidDurations')]
    public function rejectsInvalidDurations(float $duration): void
    {
        $id = new TestId('App\FooTest', 'bar');

        expect()->calling(
            static fn(): TestResult => new TestResult($id, Outcome::Passed, $duration, 0),
        )
            ->because('result durations MUST be finite and non-negative')
            ->toThrow(
                \InvalidArgumentException::class,
                message: 'Duration must be finite and zero or more.',
            );
    }

    #[Test]
    public function outcomeSuccessSemantics(): void
    {
        expect(Outcome::Passed->isSuccessful())->because('outcome success uses the required semantics')->toBeTrue();
        expect(Outcome::Skipped->isSuccessful())->because('outcome success uses the required semantics')->toBeTrue();
        expect(Outcome::Failed->isSuccessful())->because('outcome success uses the required semantics')->toBeFalse();
        expect(Outcome::Errored->isSuccessful())->because('outcome success uses the required semantics')->toBeFalse();
    }

    /**
     * @return iterable<string, array{float}>
     */
    public static function invalidDurations(): iterable
    {
        yield 'negative' => [-0.1];
        yield 'positive infinity' => [\INF];
        yield 'negative infinity' => [-\INF];
        yield 'not a number' => [\NAN];
    }
}
