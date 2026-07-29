<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Artifact;

use Greenlight\Attribute\Test;
use Greenlight\Config\ArtifactConfiguration;
use Greenlight\Core\Artifact\AttachmentError;
use Greenlight\Core\Result\Outcome;
use Greenlight\Core\Result\TestResult;
use Greenlight\Core\Test\TestId;
use Greenlight\Expect\Expect;
use Greenlight\Fixture\TempDirectory;
use Greenlight\Runner\Artifact\ArtifactStore;
use Greenlight\Runner\Artifact\TestArtifactBudget;
use Greenlight\Tests\Fixture\Artifact\CorruptingFileCopier;

final readonly class ArtifactPostCopyIntegrityTest
{
    public function __construct(private TempDirectory $tempDirectory) {}

    #[Test]
    public function postCopyCorruptionRejectsPublicationAndRemovesThePartialFile(): void
    {
        $root = $this->tempDirectory->subdirectory('post-copy-integrity');
        $store = ArtifactStore::open(
            new ArtifactConfiguration($root),
            $root,
            'run-post-copy-integrity',
            new CorruptingFileCopier(),
        );
        $id = new TestId('Example\EvidenceTest', 'fails');
        $attempt = $store->forAttempt($id, 1, new TestArtifactBudget());
        $attempt->text('evidence.txt', 'evidence');
        $staged = $attempt->seal()[0];
        $result = new TestResult(
            $id,
            Outcome::Failed,
            0.1,
            0,
            attachments: [$staged],
        );

        try {
            Expect::that(static fn(): TestResult => $store->publish($result))
                ->because('post-copy corruption MUST reject attachment publication')
                ->toThrow(
                    AttachmentError::class,
                    message: 'Published attachment content does not match its metadata.',
                )
                ->and(\is_file($staged->path))
                ->because('a rejected publication MUST NOT leave the final attachment')
                ->toBeFalse()
                ->and(\glob($staged->path . '.part-*'))
                ->because('a rejected publication MUST remove its partial attachment')
                ->toBe([]);
        } finally {
            $store->cleanup();
        }
    }
}
