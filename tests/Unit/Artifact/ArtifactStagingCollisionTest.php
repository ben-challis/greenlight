<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Artifact;

use Greenlight\Attribute\Test;
use Greenlight\Config\ArtifactConfiguration;
use Greenlight\Core\Artifact\AttachmentError;
use Greenlight\Core\Artifact\AttachmentKind;
use Greenlight\Core\Artifact\AttachmentRetention;
use Greenlight\Expect\Expect;
use Greenlight\Runner\Artifact\ArtifactSession;
use Greenlight\Runner\Artifact\ArtifactStore;
use Greenlight\Sandbox\TemporaryDirectory;

final readonly class ArtifactStagingCollisionTest
{
    public function __construct(private TemporaryDirectory $tempDirectory) {}

    #[Test]
    public function fileStagingCollisionPreservesTheExistingPartAndReleasesQuota(): void
    {
        $root = $this->tempDirectory->subdirectory('file-staging-collision');
        $source = $root . '/source.txt';
        \file_put_contents($source, 'evidence');
        [$store, $configuration, $part] = $this->store($root);

        Expect::that(static fn() => $store->stageFile(
            $source,
            'evidence.txt',
            'Example-EvidenceTest/attempt-1/01-evidence.txt',
            'text/plain',
            1,
            AttachmentRetention::OnFailure,
            $configuration,
        ))
            ->because('a staging collision MUST reject the file attachment')
            ->toThrow(
                AttachmentError::class,
                message: 'Greenlight did not create the attachment staging file.',
            );
        Expect::that((string) \file_get_contents($part))
            ->because('a rejected file attachment MUST NOT delete the existing staging part')
            ->toBe('occupied');

        \unlink($part);
        $staged = $store->stageFile(
            $source,
            'evidence.txt',
            'Example-EvidenceTest/attempt-1/01-evidence.txt',
            'text/plain',
            1,
            AttachmentRetention::OnFailure,
            $configuration,
        );

        Expect::that($staged->name)
            ->because('a rejected file attachment MUST release its run quota')
            ->toBe('evidence.txt');
    }

    #[Test]
    public function byteStagingCollisionPreservesTheExistingPartAndReleasesQuota(): void
    {
        $root = $this->tempDirectory->subdirectory('byte-staging-collision');
        [$store, $configuration, $part] = $this->store($root);

        Expect::that(static fn() => $store->stageBytes(
            'evidence',
            'evidence.txt',
            'Example-EvidenceTest/attempt-1/01-evidence.txt',
            'text/plain',
            AttachmentKind::Text,
            1,
            AttachmentRetention::OnFailure,
            $configuration,
        ))
            ->because('a staging collision MUST reject the byte attachment')
            ->toThrow(
                AttachmentError::class,
                message: 'Greenlight did not create the attachment staging file.',
            );
        Expect::that((string) \file_get_contents($part))
            ->because('a rejected byte attachment MUST NOT delete the existing staging part')
            ->toBe('occupied');

        \unlink($part);
        $staged = $store->stageBytes(
            'evidence',
            'evidence.txt',
            'Example-EvidenceTest/attempt-1/01-evidence.txt',
            'text/plain',
            AttachmentKind::Text,
            1,
            AttachmentRetention::OnFailure,
            $configuration,
        );

        Expect::that($staged->name)
            ->because('a rejected byte attachment MUST release its run quota')
            ->toBe('evidence.txt');
    }

    #[Test]
    public function finalStagingCollisionPreservesTheExistingFileAndReleasesQuota(): void
    {
        $root = $this->tempDirectory->subdirectory('final-staging-collision');
        [$store, $configuration, $path] = $this->store($root, '');

        Expect::that(static fn() => $store->stageBytes(
            'evidence',
            'evidence.txt',
            'Example-EvidenceTest/attempt-1/01-evidence.txt',
            'text/plain',
            AttachmentKind::Text,
            1,
            AttachmentRetention::OnFailure,
            $configuration,
        ))
            ->because('a final staging collision MUST reject the attachment')
            ->toThrow(
                AttachmentError::class,
                message: 'Attachment staging path already exists.',
            );
        Expect::that((string) \file_get_contents($path))
            ->because('a rejected attachment MUST NOT replace the existing staging file')
            ->toBe('occupied');

        \unlink($path);
        $staged = $store->stageBytes(
            'evidence',
            'evidence.txt',
            'Example-EvidenceTest/attempt-1/01-evidence.txt',
            'text/plain',
            AttachmentKind::Text,
            1,
            AttachmentRetention::OnFailure,
            $configuration,
        );

        Expect::that($staged->name)
            ->because('a final staging collision MUST release its run quota')
            ->toBe('evidence.txt');
    }

    /**
     * @return array{ArtifactStore, ArtifactConfiguration, non-empty-string}
     */
    private function store(string $root, string $collisionSuffix = '.part'): array
    {
        $staging = $root . '/staging';
        $storageKey = 'Example-EvidenceTest/attempt-1/01-evidence.txt';
        $collision = $staging . '/' . $storageKey . $collisionSuffix;
        \mkdir(\dirname($collision), 0o777, true);
        \file_put_contents($collision, 'occupied');
        $configuration = new ArtifactConfiguration(
            $root . '/published',
            maxRunAttachments: 1,
        );
        $store = ArtifactStore::fromSession(
            new ArtifactSession($staging, $root . '/published/run-1'),
            $configuration,
        );

        return [$store, $configuration, $collision];
    }
}
