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

final readonly class ArtifactOutputEntryTest
{
    public function __construct(private TempDirectory $tempDirectory) {}

    #[Test]
    public function nonDirectoryOutputEntriesBlockPublication(): void
    {
        $root = $this->tempDirectory->subdirectory('output-entry');
        \mkdir($root . '/published');
        $store = ArtifactStore::open(
            new ArtifactConfiguration($root . '/published'),
            $root,
            'run-1',
        );
        $id = new TestId('Example\EvidenceTest', 'publishesEvidence');
        $attachments = $store->forAttempt($id, 1, new TestArtifactBudget());
        $attachments->text('evidence.txt', 'body');
        $staged = $attachments->seal()[0];
        $firstSegment = \explode('/', $staged->storageKey)[0];
        \mkdir($store->publicDirectory(), 0o777, true);
        $blocker = $store->publicDirectory() . '/' . $firstSegment;
        \file_put_contents($blocker, 'keep');

        try {
            Expect::that(static fn(): TestResult => $store->publish(new TestResult(
                $id,
                Outcome::Failed,
                0.1,
                0,
                attachments: [$staged],
            )))
                ->because('artifact publication requires directory-only output path segments')
                ->toThrow(
                    AttachmentError::class,
                    message: 'Attachment output path contains a non-directory entry.',
                );
            Expect::that((string) \file_get_contents($blocker))
                ->because('a rejected publication MUST NOT replace the blocking entry')
                ->toBe('keep');
            Expect::that(\is_file($store->session()->stagingDirectory . '/' . $staged->storageKey))
                ->because('rejected evidence remains available for recovery')
                ->toBeTrue();
        } finally {
            $store->cleanup();
        }
    }
}
