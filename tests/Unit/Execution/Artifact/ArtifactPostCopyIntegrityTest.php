<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Execution\Artifact;

use Greenlight\Artifact\AttachmentError;
use Greenlight\Attribute\Test;
use Greenlight\Config\ArtifactConfiguration;
use Greenlight\Execution\Artifact\ArtifactStore;
use Greenlight\Execution\Artifact\TestArtifactBudget;
use Greenlight\Result\Outcome;
use Greenlight\Result\TestResult;
use Greenlight\Sandbox\TemporaryDirectory;
use Greenlight\Test\Cleanup;
use Greenlight\Test\TestId;
use Greenlight\Tests\Fixture\Execution\Artifact\CorruptingFileCopier;

use function Greenlight\expect;

final readonly class ArtifactPostCopyIntegrityTest
{
    public function __construct(
        private TemporaryDirectory $tempDirectory,
        private Cleanup $cleanup,
    ) {}

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
        $this->cleanup->defer($store->cleanup(...));
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

        expect()->calling(static fn(): TestResult => $store->publish($result))
            ->because('post-copy corruption MUST reject attachment publication')
            ->toThrow(
                AttachmentError::class,
                message: 'Published attachment content does not match its metadata.',
            );
        expect(\is_file($staged->path))
            ->because('a rejected publication MUST NOT leave the final attachment')
            ->toBeFalse();
        expect(\glob($staged->path . '.part-*'))
            ->because('a rejected publication MUST remove its partial attachment')
            ->toBe([]);
    }
}
