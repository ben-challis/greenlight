<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Artifact;

use Greenlight\Attribute\Test;
use Greenlight\Config\ArtifactConfiguration;
use Greenlight\Core\Artifact\AttachmentError;
use Greenlight\Core\Result\Outcome;
use Greenlight\Core\Result\TestResult;
use Greenlight\Core\Test\SkipTest;
use Greenlight\Core\Test\TestId;
use Greenlight\Expect\Expect;
use Greenlight\Fixture\TempDirectory;
use Greenlight\Runner\Artifact\ArtifactStore;
use Greenlight\Runner\Artifact\TestArtifactBudget;

final readonly class ArtifactOutputPermissionTest
{
    public function __construct(private TempDirectory $tempDirectory) {}

    #[Test]
    public function anUnwritableOutputParentPreservesStagedEvidence(): void
    {
        $root = $this->tempDirectory->subdirectory('output-permission');
        $readOnly = $root . '/read-only';
        \mkdir($readOnly, 0o700);
        \chmod($readOnly, 0o500);
        \clearstatcache(true, $readOnly);

        if (\is_writable($readOnly)) {
            \chmod($readOnly, 0o700);
            throw new SkipTest('The filesystem does not enforce directory write permissions.');
        }

        $store = ArtifactStore::open(
            new ArtifactConfiguration($readOnly . '/artifacts'),
            $root,
            'run-1',
        );
        $id = new TestId('Example\EvidenceTest', 'publishesEvidence');
        $attachments = $store->forAttempt($id, 1, new TestArtifactBudget());
        $attachments->text('evidence.txt', 'body');
        $staged = $attachments->seal()[0];

        try {
            Expect::that(static fn(): TestResult => $store->publish(new TestResult(
                $id,
                Outcome::Failed,
                0.1,
                0,
                attachments: [$staged],
            )))
                ->because('an unwritable output parent MUST reject attachment publication')
                ->toThrow(
                    AttachmentError::class,
                    matching: '/^Failed to create attachment output directory/',
                )
                ->and(\is_file($store->session()->stagingDirectory . '/' . $staged->storageKey))
                ->because('rejected evidence MUST remain available for recovery')
                ->toBeTrue();
        } finally {
            \chmod($readOnly, 0o700);
            $store->cleanup();
        }
    }
}
