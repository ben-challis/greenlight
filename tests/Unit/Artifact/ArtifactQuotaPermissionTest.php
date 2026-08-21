<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Artifact;

use Greenlight\Attribute\Test;
use Greenlight\Config\ArtifactConfiguration;
use Greenlight\Core\Artifact\AttachmentError;
use Greenlight\Core\Test\SkipTest;
use Greenlight\Core\Test\TestId;
use Greenlight\Expect\Expect;
use Greenlight\Runner\Artifact\ArtifactSession;
use Greenlight\Runner\Artifact\ArtifactStore;
use Greenlight\Runner\Artifact\TestArtifactBudget;
use Greenlight\Sandbox\TemporaryDirectory;

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
