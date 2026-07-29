<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Core;

use Greenlight\Attribute\Test;
use Greenlight\Core\Artifact\Attachment;
use Greenlight\Core\Artifact\StagedAttachment;
use Greenlight\Core\Wire\InvalidWirePayload;
use Greenlight\Expect\Expect;

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
