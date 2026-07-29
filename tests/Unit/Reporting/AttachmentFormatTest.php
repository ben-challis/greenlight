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

final class AttachmentFormatTest
{
    #[Test]
    public function aResultWithoutAttachmentsRendersNothing(): void
    {
        Expect::that(AttachmentFormat::render($this->result([])))
            ->because('a result without attachments renders nothing')
            ->toBe('');
    }

    #[Test]
    public function attachmentMetadataRendersExactly(): void
    {
        $attachments = $this->attachments(1);

        Expect::that(AttachmentFormat::render($this->result($attachments), '    '))
            ->because(
                'human-readable attachment metadata includes its type, size, and path',
            )
            ->toBe(
                "    attachments:\n"
                . "      attachment-1.txt (text/plain, 5 bytes): "
                . "build/attachments/attachment-1.txt\n",
            )
            ->and(AttachmentFormat::paths($attachments))
            ->because(
                'machine-readable attachment metadata includes its name and path',
            )
            ->toBe('attachment-1.txt: build/attachments/attachment-1.txt');
    }

    #[Test]
    public function attachmentListsAreBoundedAndReportTheRemainder(): void
    {
        $attachments = $this->attachments(12);
        $rendered = AttachmentFormat::render($this->result($attachments));
        $paths = AttachmentFormat::paths($attachments);

        foreach ([$rendered, $paths] as $output) {
            Expect::that($output)
                ->because('attachment lists are bounded and report the remainder')
                ->toContain('attachment-1.txt')
                ->toContain('attachment-10.txt')
                ->toContain('and 2 more')
                ->not()->toContain('attachment-11.txt');
        }
    }

    /**
     * @param list<Attachment> $attachments
     */
    private function result(array $attachments): TestResult
    {
        return new TestResult(
            new TestId('Example\AttachmentTest', 'recordsEvidence'),
            Outcome::Passed,
            0.1,
            0,
            attachments: $attachments,
        );
    }

    /**
     * @return list<Attachment>
     */
    private function attachments(int $count): array
    {
        $attachments = [];

        for ($index = 1; $index <= $count; ++$index) {
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

        return $attachments;
    }
}
