<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Execution\Artifact;

use Greenlight\Artifact\AttachmentError;
use Greenlight\Attribute\Test;
use Greenlight\Config\ArtifactConfiguration;
use Greenlight\Execution\Artifact\ArtifactSession;
use Greenlight\Execution\Artifact\ArtifactStore;
use Greenlight\Execution\Artifact\TestArtifactBudget;
use Greenlight\Expect\Expect;
use Greenlight\Sandbox\TemporaryDirectory;
use Greenlight\Test\SkipTest;
use Greenlight\Test\TestId;

final readonly class ArtifactQuotaPermissionTest
{
    public function __construct(private TemporaryDirectory $tempDirectory) {}

    #[Test]
    public function anUnreadableQuotaFileRejectsStagingUntilAccessReturns(): void
    {
        $root = $this->tempDirectory->subdirectory('quota-permission');
        $staging = $root . '/staging';
        $quota = $staging . '/.quota';
        \mkdir($staging);
        \file_put_contents($quota, '');
        \chmod($quota, 0o000);
        \clearstatcache(true, $quota);

        if (\is_readable($quota)) {
            \chmod($quota, 0o600);
            throw new SkipTest('The filesystem does not enforce file read permissions.');
        }

        $configuration = new ArtifactConfiguration($root . '/published');
        $store = ArtifactStore::fromSession(
            new ArtifactSession($staging, $root . '/published/run-1'),
            $configuration,
        );
        $attachments = $store->forAttempt(
            new TestId('Example\EvidenceTest', 'stagesEvidence'),
            1,
            new TestArtifactBudget(),
        );

        try {
            Expect::that(static fn() => $attachments->text('evidence.txt', 'body'))
                ->because('an unreadable quota file MUST reject attachment staging')
                ->toThrow(
                    AttachmentError::class,
                    message: 'Failed to lock the attachment quota.',
                );

            \chmod($quota, 0o600);
            $attachments->text('evidence.txt', 'body');

            Expect::that($attachments->collected())
                ->because('attachment staging MUST recover after quota access returns')
                ->toHaveCount(1);
        } finally {
            \chmod($quota, 0o600);
        }
    }
}
