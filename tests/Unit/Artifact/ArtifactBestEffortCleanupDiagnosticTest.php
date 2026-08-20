<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Artifact;

use Greenlight\Attribute\Test;
use Greenlight\Config\ArtifactConfiguration;
use Greenlight\Core\Artifact\AttachmentError;
use Greenlight\Core\ErrorTrap;
use Greenlight\Core\Result\Outcome;
use Greenlight\Core\Result\TestResult;
use Greenlight\Core\Test\TestId;
use Greenlight\Expect\Expect;
use Greenlight\Fixture\TempDirectory;
use Greenlight\Runner\Artifact\ArtifactStore;
use Greenlight\Runner\Artifact\TestArtifactBudget;
use Greenlight\Tests\Fixture\Artifact\DirectoryCreatingFileCopier;

final readonly class ArtifactBestEffortCleanupDiagnosticTest
{
    public function __construct(private TempDirectory $tempDirectory) {}

    #[Test]
    public function aFailedPublicationCleanupDoesNotLeakEngineDiagnostics(): void
    {
        $root = $this->tempDirectory->subdirectory('best-effort-cleanup-diagnostic');
        $copier = new DirectoryCreatingFileCopier();
        $store = ArtifactStore::open(
            new ArtifactConfiguration($root),
            $root,
            'run-cleanup-diagnostic',
            $copier,
        );
        $id = new TestId('Example\EvidenceTest', 'fails');
        $attachments = $store->forAttempt($id, 1, new TestArtifactBudget());
        $attachments->text('evidence.txt', 'evidence');
        $staged = $attachments->seal()[0];

        try {
            Expect::that(static function () use ($store, $id, $staged, &$warning): TestResult {
                return ErrorTrap::run(
                    static fn() => $store->publish(new TestResult(
                        $id,
                        Outcome::Failed,
                        0.1,
                        0,
                        attachments: [$staged],
                    )),
                    $warning,
                );
            })
                ->because('publication MUST preserve the copier failure')
                ->toThrow(
                    AttachmentError::class,
                    message: 'The fake copier stopped after it created a directory.',
                );

            Expect::that($warning)
                ->because('best-effort publication cleanup MUST not leak an engine diagnostic')
                ->toBeNull();
        } finally {
            if ($copier->destination !== null) {
                \rmdir($copier->destination);
            }

            $store->cleanup();
        }
    }
}
