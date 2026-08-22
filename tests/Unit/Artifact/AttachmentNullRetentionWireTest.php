<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Artifact;

use Greenlight\Artifact\Attachment;
use Greenlight\Artifact\StagedAttachment;
use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Wire\InvalidWirePayload;

final readonly class AttachmentNullRetentionWireTest
{
    #[Test]
    public function explicitNullRetentionIsRejected(): void
    {
        $payload = [
            'name' => 'response.json',
            'kind' => 'value',
            'mediaType' => 'application/json',
            'sizeBytes' => 2,
            'sha256' => \str_repeat('a', 64),
            'attempt' => 1,
            'path' => 'build/artifacts/response.json',
            'retention' => null,
        ];
        $message = 'Wire payload key "retention" must be a string, got null.';

        Expect::that(static fn(): Attachment => Attachment::fromWire($payload))
            ->because('an explicit null retention MUST NOT use the missing-field default')
            ->toThrow(InvalidWirePayload::class, message: $message);

        Expect::that(static fn(): StagedAttachment => StagedAttachment::fromWire([
            ...$payload,
            'storageKey' => 'attempt/response.json',
        ]))
            ->because('staged attachment retention MUST use the same wire contract')
            ->toThrow(InvalidWirePayload::class, message: $message);
    }
}
