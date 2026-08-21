<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Artifact;

use Greenlight\Attribute\Test;
use Greenlight\Config\ArtifactConfiguration;
use Greenlight\Core\Artifact\AttachmentError;
use Greenlight\Core\Test\TestId;
use Greenlight\Expect\Expect;
use Greenlight\Runner\Artifact\ArtifactSession;
use Greenlight\Runner\Artifact\ArtifactStore;
use Greenlight\Sandbox\TemporaryDirectory;

final readonly class ArtifactAttemptRecordCollisionTest
{
    public function __construct(private TemporaryDirectory $tempDirectory) {}

    #[Test]
    public function aFileAtTheTestDirectoryBlocksAttemptRecording(): void
    {
        $root = $this->tempDirectory->subdirectory('attempt-record-collision');
        $staging = $root . '/staging';
        $id = new TestId('Example\EvidenceTest', 'recordsAttempt');
        $testDirectory = $staging . '/' . ArtifactStore::testDirectory($id);
        \mkdir($staging);
        \file_put_contents($testDirectory, 'occupied');

        $store = ArtifactStore::fromSession(
            new ArtifactSession($staging, $root . '/published/run-1'),
            new ArtifactConfiguration($root . '/published'),
        );

        Expect::that(static fn() => $store->recordAttempt($id, 1))
            ->because('a non-directory staging entry MUST block attempt recording')
            ->toThrow(
                AttachmentError::class,
                matching: '/^Failed to create attachment staging subdirectory/',
            );
        Expect::that((string) \file_get_contents($testDirectory))
            ->because('a rejected attempt record MUST preserve the existing entry')
            ->toBe('occupied');
        Expect::that(\file_exists($testDirectory . '/.attempt'))
            ->because('a rejected attempt record MUST not create an attempt file')
            ->toBeFalse();
    }
}
