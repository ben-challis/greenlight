<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Core;

use Greenlight\Attribute\Test;
use Greenlight\Core\Artifact\Attachment;
use Greenlight\Core\Artifact\AttachmentKind;
use Greenlight\Core\Artifact\AttachmentRetention;
use Greenlight\Core\Artifact\StagedAttachment;
use Greenlight\Core\Result\Outcome;
use Greenlight\Core\Result\TestResult;
use Greenlight\Core\Test\TestId;
use Greenlight\Expect\Expect;
use Greenlight\Tests\Support\JsonWire;

final class TestResultAttachmentWireTest
{
    #[Test]
    public function preservesConcreteAttachmentTypesAcrossTheWire(): void
    {
        $attachment = new Attachment(
            name: 'response.json',
            kind: AttachmentKind::Value,
            mediaType: 'application/json',
            sizeBytes: 2,
            sha256: \str_repeat('a', 64),
            attempt: 1,
            path: 'build/artifacts/response.json',
            retention: AttachmentRetention::Always,
        );
        $staged = new StagedAttachment(
            name: 'trace.txt',
            kind: AttachmentKind::Text,
            mediaType: 'text/plain',
            sizeBytes: 5,
            sha256: \str_repeat('b', 64),
            attempt: 2,
            path: 'build/artifacts/trace.txt',
            retention: AttachmentRetention::OnFailure,
            storageKey: 'Example-Test/attempt-2/01-trace.txt',
        );
        $result = new TestResult(
            new TestId('Example\Test', 'recordsEvidence'),
            Outcome::Passed,
            0.1,
            0,
            attachments: [$attachment, $staged],
        );

        $restored = TestResult::fromWire(JsonWire::roundTrip($result->toWire()));

        Expect::that(\array_map(
            static fn(Attachment $restoredAttachment): string => $restoredAttachment::class,
            $restored->attachments,
        ))
            ->because('test result wire decoding MUST preserve concrete attachment types')
            ->toBe([Attachment::class, StagedAttachment::class])
            ->and($restored->attachments)
            ->because('test result wire decoding MUST preserve exact attachment metadata')
            ->toEqual([$attachment, $staged])
            ->and($restored->attachments[1] instanceof StagedAttachment
                ? $restored->attachments[1]->storageKey
                : null)
            ->because('the staged attachment storage key MUST survive the test result wire boundary')
            ->toBe('Example-Test/attempt-2/01-trace.txt');
    }
}
