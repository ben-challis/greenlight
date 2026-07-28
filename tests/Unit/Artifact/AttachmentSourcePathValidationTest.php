<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Artifact;

use Greenlight\Attribute\Test;
use Greenlight\Config\ArtifactConfiguration;
use Greenlight\Core\Artifact\AttachmentError;
use Greenlight\Core\Test\TestId;
use Greenlight\Expect\Expect;
use Greenlight\Fixture\TempDirectory;
use Greenlight\Runner\Artifact\ArtifactStore;
use Greenlight\Runner\Artifact\TestArtifactBudget;

final readonly class AttachmentSourcePathValidationTest
{
    public function __construct(private TempDirectory $tempDirectory) {}

    #[Test]
    public function nullBytesAreRejectedBeforeFileSystemAccess(): void
    {
        $root = $this->tempDirectory->subdirectory('null-source-path');
        $configuration = new ArtifactConfiguration($root);
        $store = ArtifactStore::open($configuration, $root, 'run-null-source');
        $attachments = $store->forAttempt(
            new TestId('Example\EvidenceTest', 'invalidSource'),
            1,
            new TestArtifactBudget(),
        );

        try {
            Expect::that(static fn() => $attachments->file('copy.bin', "source\0hidden.txt"))
                ->because('attachment source paths MUST be valid file-system paths')
                ->toThrow(
                    AttachmentError::class,
                    message: 'Attachment source "source\0hidden.txt" contains a null byte.',
                );
        } finally {
            $store->cleanup();
        }
    }
}
