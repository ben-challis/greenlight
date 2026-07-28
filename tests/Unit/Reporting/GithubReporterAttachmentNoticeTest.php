<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Reporting;

use Greenlight\Attribute\Test;
use Greenlight\Core\Artifact\Attachment;
use Greenlight\Core\Artifact\AttachmentKind;
use Greenlight\Core\Event\RunStarted;
use Greenlight\Core\Event\TestFinished;
use Greenlight\Core\Result\Outcome;
use Greenlight\Core\Result\TestResult;
use Greenlight\Core\Test\TestId;
use Greenlight\Expect\Expect;
use Greenlight\Reporting\GithubReporter;

final class GithubReporterAttachmentNoticeTest
{
    #[Test]
    public function attachmentDirectoryNoticeEscapesWorkflowCommandData(): void
    {
        $output = new BufferOutput();
        $reporter = new GithubReporter($output);
        $reporter->onEvent(new RunStarted(
            'run-1',
            1,
            1,
            0.0,
            "build/artifacts%name\r\n::error::injected",
        ));
        $reporter->onEvent(new TestFinished(
            new TestResult(
                new TestId('Acme\\EvidenceTest', 'passes'),
                Outcome::Passed,
                0.1,
                0,
                attachments: [
                    new Attachment(
                        'evidence.txt',
                        AttachmentKind::Text,
                        'text/plain',
                        8,
                        \str_repeat('a', 64),
                        1,
                        'build/artifacts/evidence.txt',
                    ),
                ],
            ),
            1.0,
        ));
        $reporter->finish();

        Expect::that($output->buffer())
            ->because('the attachment notice MUST escape workflow-command data')
            ->toBe(
                '::notice::Greenlight attachments: '
                . 'build/artifacts%25name%0D%0A::error::injected'
                . "\n",
            );
    }
}
