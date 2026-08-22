<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Artifact;

use Greenlight\Artifact\AttachmentError;
use Greenlight\Artifact\UnavailableAttachments;
use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;

final class UnavailableAttachmentsTest
{
    /**
     * @param \Closure(UnavailableAttachments): void $call
     */
    #[Test]
    #[DataSet('attachmentCalls')]
    public function everyAttachmentTypeFailsOutsideAnActiveAttempt(\Closure $call): void
    {
        $attachments = new UnavailableAttachments();

        Expect::that(static fn() => $call($attachments))
            ->because('attachments are unavailable outside an active attempt')
            ->toThrow(
                AttachmentError::class,
                message: 'Attachments are not available outside an active test attempt.',
            );
    }

    /**
     * @return iterable<string, array{\Closure(UnavailableAttachments): void}>
     */
    public static function attachmentCalls(): iterable
    {
        yield 'value' => [
            static fn(UnavailableAttachments $attachments) => $attachments->value('state', ['ready' => true]),
        ];

        yield 'text' => [
            static fn(UnavailableAttachments $attachments) => $attachments->text('log', 'Ready.'),
        ];

        yield 'bytes' => [
            static fn(UnavailableAttachments $attachments) => $attachments->bytes('image', "\x89PNG"),
        ];

        yield 'file' => [
            static fn(UnavailableAttachments $attachments) => $attachments->file('report', '/tmp/report.txt'),
        ];
    }
}
