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

final readonly class ArtifactStagingRootCollisionTest
{
    public function __construct(private TemporaryDirectory $tempDirectory) {}

    #[Test]
    public function aFileAtTheStagingRootParentBlocksAttachments(): void
    {
        $root = $this->tempDirectory->subdirectory('staging-root-collision');
        $blocker = $root . '/blocked';
        $staging = $blocker . '/staging';
        \file_put_contents($blocker, 'occupied');

        $configuration = new ArtifactConfiguration($root . '/published');
        $store = ArtifactStore::fromSession(
            new ArtifactSession($staging, $root . '/published/run-1'),
            $configuration,
        );
        $stage = static fn() => $store->stageBytes(
            'evidence',
            'evidence.txt',
            'Example-EvidenceTest/attempt-1/01-evidence.txt',
            'text/plain',
            AttachmentKind::Text,
            1,
            AttachmentRetention::OnFailure,
            $configuration,
        );

        expect()->calling($stage)
            ->because('a non-directory staging parent MUST block attachments')
            ->toThrow(
                AttachmentError::class,
                matching: '/^Failed to create attachment staging directory/',
            );
        expect((string) \file_get_contents($blocker))
            ->because('a rejected staging root MUST preserve the existing entry')
            ->toBe('occupied');
        expect(\is_dir($staging))
            ->because('a rejected staging root MUST not create a directory')
            ->toBeFalse();

        \unlink($blocker);
        \mkdir($blocker);

        expect($stage()->name)
            ->because('staging MUST succeed after the parent becomes a directory')
            ->toBe('evidence.txt');
    }
}
