<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Execution\Artifact;

use Greenlight\Artifact\AttachmentError;
use Greenlight\Attribute\Test;
use Greenlight\Config\ArtifactConfiguration;
use Greenlight\Execution\Artifact\ArtifactStore;
use Greenlight\Execution\Artifact\TestArtifactBudget;
use Greenlight\Sandbox\TemporaryDirectory;
use Greenlight\Test\Cleanup;
use Greenlight\Test\TestId;

use function Greenlight\expect;

final readonly class ArtifactFilesystemWarningTest
{
    public function __construct(
        private TemporaryDirectory $tempDirectory,
        private Cleanup $cleanup,
    ) {}

    #[Test]
    public function aFailedStagingWriteReportsItsWarningInTheError(): void
    {
        $root = $this->tempDirectory->subdirectory('staging-write-warning');
        $store = ArtifactStore::open(new ArtifactConfiguration($root), $root, 'run-staging-warning');
        $this->cleanup->defer($store->cleanup(...));
        $id = new TestId('Example\EvidenceTest', 'fails');
        $attachments = $store->forAttempt($id, 1, new TestArtifactBudget());
        $attachments->text('first.txt', 'first');
        $testDirectory = $store->session()->stagingDirectory . '/' . ArtifactStore::testDirectory($id) . '/attempt-1';
        \chmod($testDirectory, 0o500);
        $this->cleanup->defer(static fn() => \chmod($testDirectory, 0o700));
        $warnings = [];
        \set_error_handler(static function (int $level, string $message) use (&$warnings): bool {
            $warnings[] = $message;

            return true;
        });

        try {
            $failure = null;
            try {
                $attachments->text('second.txt', 'second');
            } catch (AttachmentError $error) {
                $failure = $error;
            }
        } finally {
            \restore_error_handler();
        }

        expect($warnings)
            ->because('a staging write failure MUST NOT reach the error handler of the test')
            ->toBe([]);
        expect($failure?->getMessage() ?? '')
            ->toStartWith('Greenlight did not create the attachment staging file: ')
            ->toContain('Permission denied');
    }
}
