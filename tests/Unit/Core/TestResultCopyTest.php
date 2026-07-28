<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Core;

use Greenlight\Attribute\Test;
use Greenlight\Core\Artifact\Attachment;
use Greenlight\Core\Artifact\AttachmentKind;
use Greenlight\Core\Result\CapturedOutput;
use Greenlight\Core\Result\FailureDetail;
use Greenlight\Core\Result\Outcome;
use Greenlight\Core\Result\OutcomeTransformation;
use Greenlight\Core\Result\TestResult;
use Greenlight\Core\Result\ThrowableDetail;
use Greenlight\Core\Test\TestId;
use Greenlight\Expect\Expect;

final class TestResultCopyTest
{
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
            ->toBe($original)
            ->and($original->attempts)
            ->because('recovering an attempt count MUST NOT change the original result')
            ->toBe(2)
            ->and($recovered->toWire())
            ->because('the replacement MUST preserve all result state except the recovered attempt count')
            ->toBe($expected);
    }
}
