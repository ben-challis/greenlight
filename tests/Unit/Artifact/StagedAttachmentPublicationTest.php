<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Artifact;

use Greenlight\Artifact\Attachment;
use Greenlight\Artifact\AttachmentKind;
use Greenlight\Artifact\AttachmentRetention;
use Greenlight\Artifact\StagedAttachment;
use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;

final class StagedAttachmentPublicationTest
{
    #[Test]
    public function publicationPreservesMetadataAndRemovesTheStagingCoordinate(): void
    {
        $staged = new StagedAttachment(
            name: 'response.json',
            kind: AttachmentKind::Value,
            mediaType: 'application/problem+json',
            sizeBytes: 37,
            sha256: \str_repeat('b', 64),
            attempt: 3,
            path: 'build/artifacts/response.json',
            retention: AttachmentRetention::Always,
            storageKey: 'run/test/attempt-3/response.json',
        );

        $published = $staged->published();

        Expect::that($published::class)
            ->because('published metadata MUST remove the private staging coordinate')
            ->toBe(Attachment::class);
        Expect::that($published)
            ->because('published metadata MUST preserve every public attachment field')
            ->toEqual(new Attachment(
                name: 'response.json',
                kind: AttachmentKind::Value,
                mediaType: 'application/problem+json',
                sizeBytes: 37,
                sha256: \str_repeat('b', 64),
                attempt: 3,
                path: 'build/artifacts/response.json',
                retention: AttachmentRetention::Always,
            ));
    }
}
