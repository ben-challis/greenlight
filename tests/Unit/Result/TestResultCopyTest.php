<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Result;

use Greenlight\Artifact\Attachment;
use Greenlight\Artifact\AttachmentKind;
use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Result\CapturedOutput;
use Greenlight\Result\FailureDetail;
use Greenlight\Result\Outcome;
use Greenlight\Result\OutcomeTransformation;
use Greenlight\Result\TestResult;
use Greenlight\Result\ThrowableDetail;
use Greenlight\Test\TestId;

final class TestResultCopyTest
{
    /**
     * @param callable(TestResult): TestResult $mutate
     * @param array<string, mixed> $changes
     */
    #[Test]
    #[DataSet('resultMutations')]
    public function resultMutatorsReplaceOnlyTheirTargetState(callable $mutate, array $changes): void
    {
        $original = $this->completeResult();
        $originalWire = $original->toWire();
        $expected = \array_replace($originalWire, $changes);

        $replacement = $mutate($original);

        Expect::that($replacement)
            ->because('a result mutation MUST produce a replacement result')
            ->not()
            ->toBe($original);
        Expect::that($original->toWire())
            ->because('a result mutation MUST NOT change the original result')
            ->toBe($originalWire);
        Expect::that($replacement->toWire())
            ->because('a result mutation MUST preserve all state that it does not replace')
            ->toBe($expected);
    }

    #[Test]
    public function withAttemptsReplacesOnlyTheAttemptCount(): void
    {
        $original = new TestResult(
            id: new TestId('Example\\RetriedTest', 'failsThenPasses', 'row'),
            outcome: Outcome::Failed,
            durationSeconds: 0.125,
            memoryDeltaBytes: 2048,
            attempts: 2,
            failures: [new FailureDetail('expected ready', 'ready', 'waiting')],
            error: new ThrowableDetail(\RuntimeException::class, 'worker stopped', '/tests/RetriedTest.php', 42),
            skipReason: 'dependency is unavailable',
            transformations: [
                new OutcomeTransformation('quarantine', Outcome::Passed, Outcome::Failed),
            ],
            output: new CapturedOutput('captured output', stdoutTruncated: true),
            risky: true,
            expectations: 7,
            attachments: [
                new Attachment(
                    'failure.txt',
                    AttachmentKind::Text,
                    'text/plain',
                    7,
                    \str_repeat('a', 64),
                    2,
                    'artifacts/failure.txt',
                ),
            ],
        );
        $expected = $original->toWire();
        $expected['attempts'] = 4;

        $recovered = $original->withAttempts(4);

        Expect::that($recovered)
            ->because('recovering an attempt count MUST produce a replacement result')
            ->not()
            ->toBe($original);
        Expect::that($original->attempts)
            ->because('recovering an attempt count MUST NOT change the original result')
            ->toBe(2);
        Expect::that($recovered->toWire())
            ->because('the replacement MUST preserve all result state except the recovered attempt count')
            ->toBe($expected);
    }

    /**
     * @return iterable<
     *     string,
     *     array{
     *         callable(TestResult): TestResult,
     *         array<string, mixed>,
     *     },
     * >
     */
    public static function resultMutations(): iterable
    {
        $replacementAttachment = new Attachment(
            'replacement.txt',
            AttachmentKind::Text,
            'text/plain',
            11,
            \str_repeat('b', 64),
            3,
            'artifacts/replacement.txt',
        );
        $replacementError = new ThrowableDetail(
            \LogicException::class,
            'replacement error',
            '/tests/ReplacementTest.php',
            24,
        );
        $replacementFailure = new FailureDetail('replacement failure', 'ready', 'waiting');

        yield 'risky status' => [
            static fn(TestResult $result): TestResult => $result->asRisky(),
            ['risky' => true],
        ];
        yield 'attachments' => [
            static fn(TestResult $result): TestResult => $result->withAttachments([$replacementAttachment]),
            ['attachments' => [$replacementAttachment->toWire()]],
        ];
        yield 'error' => [
            static fn(TestResult $result): TestResult => $result->erroredBy($replacementError),
            [
                'outcome' => Outcome::Errored->value,
                'error' => $replacementError->toWire(),
            ],
        ];
        yield 'failures' => [
            static fn(TestResult $result): TestResult => $result->withFailures([$replacementFailure]),
            ['failures' => [$replacementFailure->toWire()]],
        ];
    }

    private function completeResult(): TestResult
    {
        return new TestResult(
            id: new TestId('Example\\CopyTest', 'preservesState', 'row'),
            outcome: Outcome::Failed,
            durationSeconds: 0.125,
            memoryDeltaBytes: 2048,
            attempts: 2,
            failures: [new FailureDetail('expected ready', 'ready', 'waiting')],
            error: new ThrowableDetail(
                \RuntimeException::class,
                'worker stopped',
                '/tests/CopyTest.php',
                42,
            ),
            skipReason: 'dependency is unavailable',
            transformations: [
                new OutcomeTransformation('quarantine', Outcome::Passed, Outcome::Failed),
            ],
            output: new CapturedOutput('captured output', stdoutTruncated: true),
            expectations: 7,
            attachments: [
                new Attachment(
                    'failure.txt',
                    AttachmentKind::Text,
                    'text/plain',
                    7,
                    \str_repeat('a', 64),
                    2,
                    'artifacts/failure.txt',
                ),
            ],
        );
    }
}
