<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Artifact;

use Greenlight\Attribute\Test;
use Greenlight\Config\ArtifactConfiguration;
use Greenlight\Core\Artifact\AttachmentError;
use Greenlight\Core\Artifact\AttachmentKind;
use Greenlight\Core\Artifact\AttachmentRetention;
use Greenlight\Expect\Expect;
use Greenlight\Fixture\TempDirectory;
use Greenlight\Runner\Artifact\ArtifactSession;
use Greenlight\Runner\Artifact\ArtifactStore;

final readonly class ArtifactStagingDirectoryCollisionTest
{
    public function __construct(private TempDirectory $tempDirectory) {}

    #[Test]
    public function aFileAtAStagingDirectoryBlocksTheAttachment(): void
    {
        $root = $this->tempDirectory->subdirectory('staging-directory-collision');
        $staging = $root . '/staging';
        $storageKey = 'blocked/attempt-1/01-evidence.txt';
        $blocker = $staging . '/blocked';
        \mkdir($staging);
        \file_put_contents($blocker, 'occupied');

        $configuration = new ArtifactConfiguration(
            $root . '/published',
            maxRunAttachments: 1,
        );
        $store = ArtifactStore::fromSession(
            new ArtifactSession($staging, $root . '/published/run-1'),
            $configuration,
        );

        Expect::that(static fn() => $store->stageBytes(
            'evidence',
            'evidence.txt',
            $storageKey,
            'text/plain',
            AttachmentKind::Text,
            1,
            AttachmentRetention::OnFailure,
            $configuration,
        ))
            ->because('a non-directory staging entry MUST block the attachment')
            ->toThrow(
                AttachmentError::class,
                matching: '/^Failed to create attachment staging subdirectory/',
            );
        Expect::that((string) \file_get_contents($blocker))
            ->because('a rejected attachment MUST preserve the existing entry')
            ->toBe('occupied');

        \unlink($blocker);
        $staged = $store->stageBytes(
            'evidence',
            'evidence.txt',
            $storageKey,
            'text/plain',
            AttachmentKind::Text,
            1,
            AttachmentRetention::OnFailure,
            $configuration,
        );

        Expect::that($staged->name)
            ->because('a rejected attachment MUST release its run quota')
            ->toBe('evidence.txt');
    }
}
