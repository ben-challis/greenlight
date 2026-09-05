<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Reporting;

use Greenlight\Artifact\Attachment;
use Greenlight\Artifact\AttachmentKind;
use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Event\TestFinished;
use Greenlight\Expect\Expect;
use Greenlight\Reporting\PlainReporter;
use Greenlight\Reporting\TtyReporter;
use Greenlight\Result\CapturedOutput;
use Greenlight\Result\Outcome;
use Greenlight\Result\TestResult;
use Greenlight\Test\TestId;

final readonly class SuccessfulResultRetentionTest
{
    /** @return iterable<string, array{bool, Outcome}> */
    public static function summaries(): iterable
    {
        yield 'plain skipped' => [false, Outcome::Skipped];
        yield 'plain retried pass' => [false, Outcome::Passed];
        yield 'tty skipped' => [true, Outcome::Skipped];
        yield 'tty retried pass' => [true, Outcome::Passed];
    }

    #[Test]
    #[DataSet('summaries')]
    public function successfulFootersReleaseCapturedOutput(bool $tty, Outcome $outcome): void
    {
        $output = new BufferOutput();
        $reporter = $tty
            ? new TtyReporter($output, color: false, cursor: false)
            : new PlainReporter($output);
        $captured = new CapturedOutput('Successful output does not appear in the footer.');
        $reference = \WeakReference::create($captured);
        $result = new TestResult(
            new TestId('ExampleTest', 'example'),
            $outcome,
            0.01,
            0,
            attempts: 2,
            skipReason: 'Example skip reason.',
            output: $captured,
            attachments: [new Attachment(
                'response.json',
                AttachmentKind::File,
                'application/json',
                2,
                \str_repeat('0', 64),
                2,
                'artifacts/response.json',
            )],
        );
        $reporter->onEvent(new TestFinished($result, 1.0));
        unset($result, $captured);

        Expect::that($reference->get())
            ->because('successful footers do not need captured output')
            ->toBeNull();

        $reporter->finish();

        Expect::that($output->buffer())->toContain('artifacts/response.json');
        Expect::that($output->buffer())->toContain($outcome === Outcome::Skipped
            ? 'ExampleTest::example (Example skip reason.)'
            : 'ExampleTest::example (2 attempts)');
    }
}
