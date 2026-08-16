<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Reporting;

use Greenlight\Attribute\Test;
use Greenlight\Core\Artifact\Attachment;
use Greenlight\Core\Artifact\AttachmentKind;
use Greenlight\Core\Result\Outcome;
use Greenlight\Core\Result\TestResult;
use Greenlight\Core\Test\TestId;
use Greenlight\Expect\Expect;
use Greenlight\Reporting\AttachmentFormat;

final class AttachmentFormatBoundaryTest
{
    #[Test]
    public function exactlyTenAttachmentsDoNotReportAnEmptyRemainder(): void
    {
        $attachments = [];

        for ($index = 1; $index <= 10; ++$index) {
            $attachments[] = new Attachment(
                \sprintf('attachment-%d.txt', $index),
                AttachmentKind::Text,
                'text/plain',
                5,
                \str_repeat('a', 64),
                1,
                \sprintf('build/attachments/attachment-%d.txt', $index),
            );
        }

        $result = new TestResult(
            new TestId('Example\AttachmentTest', 'recordsEvidence'),
            Outcome::Passed,
            0.1,
            0,
            attachments: $attachments,
        );

        Expect::that(AttachmentFormat::render($result))
            ->because('the exact attachment display limit MUST NOT report an empty remainder')
            ->toContain('attachment-10.txt')
            ->not()
            ->toContain('and 0 more');
        Expect::that(AttachmentFormat::paths($attachments))
            ->because('the exact attachment path limit MUST NOT report an empty remainder')
            ->toContain('attachment-10.txt')
            ->not()
            ->toContain('and 0 more');
    }
}
