<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Execution\Artifact;

use Greenlight\Artifact\AttachmentError;
use Greenlight\Artifact\AttachmentKind;
use Greenlight\Artifact\AttachmentRetention;
use Greenlight\Attribute\Test;
use Greenlight\Config\ArtifactConfiguration;
use Greenlight\Execution\Artifact\ArtifactSession;
use Greenlight\Execution\Artifact\ArtifactStore;
use Greenlight\Sandbox\TemporaryDirectory;

use function Greenlight\expect;

final readonly class ArtifactMetadataCollisionTest
{
    public function __construct(private TemporaryDirectory $tempDirectory) {}

    #[Test]
    public function metadataCollisionPreservesTheBlockerAndReleasesQuota(): void
    {
        $root = $this->tempDirectory->subdirectory('metadata-collision');
        $staging = $root . '/staging';
        $storageKey = 'Example-EvidenceTest/attempt-1/01-evidence.txt';
        $metadata = $staging . '/' . $storageKey . '.meta.json';
        \mkdir($metadata, 0o777, true);
        $configuration = new ArtifactConfiguration(
            $root . '/published',
            maxRunAttachments: 1,
        );
        $store = ArtifactStore::fromSession(
            new ArtifactSession($staging, $root . '/published/run-1'),
            $configuration,
        );

        expect()->calling(static fn() => $store->stageBytes(
            'evidence',
            'evidence.txt',
            $storageKey,
            'text/plain',
            AttachmentKind::Text,
            1,
            AttachmentRetention::OnFailure,
            $configuration,
        ))
            ->because('a metadata collision MUST report its primary finalization failure')
            ->toThrow(
                AttachmentError::class,
                message: 'Failed to finalize attachment recovery metadata.',
            );
        expect(\is_dir($metadata))
            ->because('rollback MUST preserve pre-existing metadata blockers')
            ->toBeTrue();
        expect(\file_exists($staging . '/' . $storageKey))
            ->because('rollback MUST remove attachment content without completed metadata')
            ->toBeFalse();

        \rmdir($metadata);
        $staged = $store->stageBytes(
            'replacement',
            'replacement.txt',
            $storageKey,
            'text/plain',
            AttachmentKind::Text,
            1,
            AttachmentRetention::OnFailure,
            $configuration,
        );

        expect($staged->name)
            ->because('a metadata failure MUST release its run quota')
            ->toBe('replacement.txt');
    }
}
