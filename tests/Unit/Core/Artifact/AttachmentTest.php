<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Core\Artifact;

use Greenlight\Attribute\Test;
use Greenlight\Core\Artifact\Attachment;
use Greenlight\Core\Artifact\AttachmentRetention;
use Greenlight\Expect\Expect;

final class AttachmentTest
{
    #[Test]
    public function wirePayloadWithoutRetentionUsesTheBackwardCompatibleDefault(): void
    {
        $attachment = Attachment::fromWire([
            'name' => 'diagnostic',
            'kind' => 'text',
            'mediaType' => 'text/plain',
            'sizeBytes' => 12,
            'sha256' => \str_repeat('a', 64),
            'attempt' => 1,
            'path' => 'diagnostic.txt',
        ]);

        Expect::that($attachment->retention)
            ->because('older wire payloads MUST retain attachments only on failure')
            ->toBe(AttachmentRetention::OnFailure)
            ->and($attachment->toWire()['retention'])
            ->toBe(AttachmentRetention::OnFailure->value);
    }
}
