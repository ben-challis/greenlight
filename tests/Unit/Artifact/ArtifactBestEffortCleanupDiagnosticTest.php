<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Artifact;

use Greenlight\Artifact\AttachmentError;
use Greenlight\Attribute\Test;
use Greenlight\Config\ArtifactConfiguration;
use Greenlight\Expect\Expect;
use Greenlight\Internal\Php\ErrorTrap;
use Greenlight\Result\Outcome;
use Greenlight\Result\TestResult;
use Greenlight\Runner\Artifact\ArtifactStore;
use Greenlight\Runner\Artifact\TestArtifactBudget;
use Greenlight\Sandbox\TemporaryDirectory;
use Greenlight\Test\Cleanup;
use Greenlight\Test\TestId;
use Greenlight\Tests\Fixture\Artifact\DirectoryCreatingFileCopier;

final readonly class ArtifactBestEffortCleanupDiagnosticTest
{
    public function __construct(
        private TemporaryDirectory $tempDirectory,
        private Cleanup $cleanup,
    ) {}

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
        $this->cleanup->defer($store->cleanup(...));
        $this->cleanup->defer(static function () use ($copier): void {
            if ($copier->destination !== null) {
                \rmdir($copier->destination);
            }
        });
        $id = new TestId('Example\EvidenceTest', 'fails');
        $attachments = $store->forAttempt($id, 1, new TestArtifactBudget());
        $attachments->text('evidence.txt', 'evidence');
        $staged = $attachments->seal()[0];

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
    }
}
