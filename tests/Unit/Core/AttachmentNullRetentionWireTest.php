<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Core;

use Greenlight\Attribute\Test;
use Greenlight\Core\Artifact\Attachment;
use Greenlight\Core\Artifact\AttachmentKind;
use Greenlight\Core\Wire\InvalidWirePayload;
use Greenlight\Expect\Expect;

final class AttachmentNullRetentionWireTest
{
    #[Test]
    public function explicitNullRetentionIsRejected(): void
    {
        Expect::that(static fn(): Attachment => Attachment::fromWire([
            'name' => 'response.json',
            'kind' => AttachmentKind::Value->value,
            'mediaType' => 'application/json',
            'sizeBytes' => 2,
            'sha256' => \str_repeat('a', 64),
            'attempt' => 1,
            'path' => 'build/artifacts/response.json',
            'retention' => null,
        ]))
            ->because('explicit null attachment retention is malformed')
            ->toThrow(
                InvalidWirePayload::class,
                message: 'Wire payload key "retention" must be a string, got null.',
            );
    }
}
