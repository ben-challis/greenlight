<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Artifact;

use Greenlight\Attribute\Test;
use Greenlight\Config\ArtifactConfiguration;
use Greenlight\Core\Artifact\AttachmentError;
use Greenlight\Core\Test\TestId;
use Greenlight\Expect\Expect;
use Greenlight\Runner\Artifact\ArtifactStore;
use Greenlight\Runner\Artifact\TestArtifactBudget;
use Greenlight\Sandbox\TemporaryDirectory;
use Greenlight\Test\Cleanup;

final readonly class AttachmentSourcePathValidationTest
{
    public function __construct(
        private TemporaryDirectory $tempDirectory,
        private Cleanup $cleanup,
    ) {}

    #[Test]
    public function nullBytesAreRejectedBeforeFileSystemAccess(): void
    {
        $root = $this->tempDirectory->subdirectory('null-source-path');
        $configuration = new ArtifactConfiguration($root);
        $store = ArtifactStore::open($configuration, $root, 'run-null-source');
        $this->cleanup->defer($store->cleanup(...));
        $attachments = $store->forAttempt(
            new TestId('Example\EvidenceTest', 'invalidSource'),
            1,
            new TestArtifactBudget(),
        );

        Expect::that(static fn() => $attachments->file('copy.bin', "source\0hidden.txt"))
            ->because('attachment source paths MUST be valid file-system paths')
            ->toThrow(
                AttachmentError::class,
                message: 'Attachment source "source\0hidden.txt" contains a null byte.',
            );
    }
}
