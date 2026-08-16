<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Artifact;

use Greenlight\Attribute\Test;
use Greenlight\Config\ArtifactConfiguration;
use Greenlight\Core\Artifact\AttachmentError;
use Greenlight\Core\Test\TestId;
use Greenlight\Expect\Expect;
use Greenlight\Fixture\TempDirectory;
use Greenlight\Runner\Artifact\ArtifactSession;
use Greenlight\Runner\Artifact\ArtifactStore;
use Greenlight\Runner\Artifact\TestArtifactBudget;

final readonly class ArtifactQuotaTest
{
    public function __construct(private TempDirectory $tempDirectory) {}

    #[Test]
    public function aQuotaSymlinkCannotRedirectSharedAccounting(): void
    {
        $root = $this->tempDirectory->subdirectory('quota-symlink');
        $staging = $root . '/staging';
        $outside = $root . '/outside-quota';
        \mkdir($staging);
        \file_put_contents($outside, 'untouched');
        \symlink($outside, $staging . '/.quota');
        $store = ArtifactStore::fromSession(
            new ArtifactSession($staging, $root . '/published/run-1'),
            new ArtifactConfiguration($root . '/published'),
        );
        $attachments = $store->forAttempt(
            new TestId('Example\EvidenceTest', 'recordsEvidence'),
            1,
            new TestArtifactBudget(),
        );

        Expect::that(static function () use ($attachments): void {
            $attachments->text('evidence.txt', 'body');
        })
            ->because('shared quota accounting MUST NOT follow symbolic links')
            ->toThrow(
                AttachmentError::class,
                message: 'Attachment quota path is unsafe.',
            );
        Expect::that((string) \file_get_contents($outside))
            ->because('a rejected quota path MUST NOT change its target')
            ->toBe('untouched');
    }

    #[Test]
    public function corruptRunQuotaMetadataFailsBeforeStaging(): void
    {
        $root = $this->tempDirectory->subdirectory('corrupt-run-quota');
        $staging = $root . '/staging';
        \mkdir($staging);
        \file_put_contents($staging . '/.quota', 'not quota metadata');
        $store = ArtifactStore::fromSession(
            new ArtifactSession($staging, $root . '/published/run-1'),
            new ArtifactConfiguration($root . '/published'),
        );
        $attachments = $store->forAttempt(
            new TestId('Example\EvidenceTest', 'recordsEvidence'),
            1,
            new TestArtifactBudget(),
        );

        Expect::that(static function () use ($attachments): void {
            $attachments->text('evidence.txt', 'body');
        })
            ->because('corrupt shared quota metadata MUST stop attachment staging')
            ->toThrow(
                AttachmentError::class,
                message: 'Attachment quota metadata is corrupt.',
            );
        Expect::that(\glob($staging . '/*/attempt-*'))
            ->because('a rejected quota reservation MUST NOT leave staging data')
            ->toBe([]);
    }
}
