<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Artifact;

use Greenlight\Attribute\Test;
use Greenlight\Core\Artifact\AttachmentError;
use Greenlight\Core\Artifact\UnavailableAttachments;
use Greenlight\Expect\Expect;

final class UnavailableAttachmentsTest
{
    #[Test]
    public function everyAttachmentTypeFailsOutsideAnActiveAttempt(): void
    {
        $attachments = new UnavailableAttachments();
        $calls = [
            'value' => static fn() => $attachments->value('state', ['ready' => true]),
            'text' => static fn() => $attachments->text('log', 'Ready.'),
            'bytes' => static fn() => $attachments->bytes('image', "\x89PNG"),
            'file' => static fn() => $attachments->file('report', '/tmp/report.txt'),
        ];

        foreach ($calls as $type => $call) {
            Expect::that($call)
                ->because(\sprintf('%s attachments are unavailable outside an active attempt', $type))
                ->toThrow(
                    AttachmentError::class,
                    message: 'Attachments are not available outside an active test attempt.',
                );
        }
    }
}
