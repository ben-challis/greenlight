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

final readonly class ArtifactStagingParentTest
{
    public function __construct(private TempDirectory $tempDirectory) {}

    #[Test]
    public function nonDirectoryStagingParentsPreserveTheEntryAndReleaseQuota(): void
    {
        $root = $this->tempDirectory->subdirectory('staging-parent-entry');
        $staging = $root . '/staging';
        \mkdir($staging);
        $blocker = $staging . '/blocked';
        \file_put_contents($blocker, 'keep');
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
            'blocked/01-evidence.txt',
            'text/plain',
            AttachmentKind::Text,
            1,
            AttachmentRetention::OnFailure,
            $configuration,
        ))
            ->because('a non-directory staging parent MUST reject the attachment')
            ->toThrow(
                AttachmentError::class,
                message: 'Failed to create attachment staging subdirectory: mkdir(): File exists.',
            )
            ->and((string) \file_get_contents($blocker))
            ->because('a rejected attachment MUST NOT replace the blocking entry')
            ->toBe('keep');

        $staged = $store->stageBytes(
            'evidence',
            'evidence.txt',
            'allowed/01-evidence.txt',
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
