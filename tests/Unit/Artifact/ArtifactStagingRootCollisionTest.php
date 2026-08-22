<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Artifact;

use Greenlight\Artifact\AttachmentError;
use Greenlight\Artifact\AttachmentKind;
use Greenlight\Artifact\AttachmentRetention;
use Greenlight\Attribute\Test;
use Greenlight\Config\ArtifactConfiguration;
use Greenlight\Expect\Expect;
use Greenlight\Runner\Artifact\ArtifactSession;
use Greenlight\Runner\Artifact\ArtifactStore;
use Greenlight\Sandbox\TemporaryDirectory;

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

        Expect::that($stage)
            ->because('a non-directory staging parent MUST block attachments')
            ->toThrow(
                AttachmentError::class,
                matching: '/^Failed to create attachment staging directory/',
            );
        Expect::that((string) \file_get_contents($blocker))
            ->because('a rejected staging root MUST preserve the existing entry')
            ->toBe('occupied');
        Expect::that(\is_dir($staging))
            ->because('a rejected staging root MUST not create a directory')
            ->toBeFalse();

        \unlink($blocker);
        \mkdir($blocker);

        Expect::that($stage()->name)
            ->because('staging MUST succeed after the parent becomes a directory')
            ->toBe('evidence.txt');
    }
}
