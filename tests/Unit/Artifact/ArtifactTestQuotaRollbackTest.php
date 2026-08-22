<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Artifact;

use Greenlight\Artifact\AttachmentError;
use Greenlight\Attribute\Test;
use Greenlight\Config\ArtifactConfiguration;
use Greenlight\Expect\Expect;
use Greenlight\Runner\Artifact\ArtifactStore;
use Greenlight\Runner\Artifact\TestArtifactBudget;
use Greenlight\Sandbox\TemporaryDirectory;
use Greenlight\Test\Cleanup;
use Greenlight\Test\TestId;

final readonly class ArtifactTestQuotaRollbackTest
{
    public function __construct(
        private TemporaryDirectory $tempDirectory,
        private Cleanup $cleanup,
    ) {}

    #[Test]
    public function aTestQuotaRejectionReleasesTheRunQuota(): void
    {
        $root = $this->tempDirectory->subdirectory('test-quota-rollback');
        $configuration = new ArtifactConfiguration(
            $root,
            maxAttachmentsPerTest: 10,
            maxAttachmentBytes: 4,
            maxTestBytes: 4,
            maxRunAttachments: 10,
            maxRunBytes: 6,
        );
        $store = ArtifactStore::open($configuration, $root, 'run-1');
        $this->cleanup->defer($store->cleanup(...));

        $first = $store->forAttempt(
            new TestId('Example\EvidenceTest', 'first'),
            1,
            new TestArtifactBudget(),
        );
        $first->text('accepted.txt', '1234');

        Expect::that(static fn() => $first->text('rejected.txt', '12'))
            ->because('the per-test byte limit MUST reject excess evidence')
            ->toThrow(
                AttachmentError::class,
                message: 'Attachments for this test exceed the limit of 4 bytes.',
            );

        $second = $store->forAttempt(
            new TestId('Example\EvidenceTest', 'second'),
            1,
            new TestArtifactBudget(),
        );
        $second->text('accepted.txt', '12');

        Expect::that($second->collected())
            ->because('a test quota rejection MUST release its run quota')
            ->toHaveCount(1);
    }

    #[Test]
    public function aMachineMaximumTestBudgetRejectsWithoutOverflowAndReleasesTheRunQuota(): void
    {
        $root = $this->tempDirectory->subdirectory('test-quota-overflow');
        $configuration = new ArtifactConfiguration(
            $root,
            maxAttachmentsPerTest: 2,
            maxAttachmentBytes: 1,
            maxTestBytes: \PHP_INT_MAX,
            maxRunAttachments: 2,
            maxRunBytes: 1,
        );
        $store = ArtifactStore::open($configuration, $root, 'run-1');
        $this->cleanup->defer($store->cleanup(...));
        $budget = new TestArtifactBudget();
        $budget->bytes = \PHP_INT_MAX;

        $first = $store->forAttempt(
            new TestId('Example\EvidenceTest', 'first'),
            1,
            $budget,
        );

        Expect::that(static fn() => $first->text('rejected.txt', 'x'))
            ->because('per-test quota arithmetic MUST NOT overflow')
            ->toThrow(
                AttachmentError::class,
                message: \sprintf(
                    'Attachments for this test exceed the limit of %d bytes.',
                    \PHP_INT_MAX,
                ),
            );
        Expect::that($budget->bytes)
            ->because('a rejected attachment MUST NOT change the test budget')
            ->toBe(\PHP_INT_MAX);

        $second = $store->forAttempt(
            new TestId('Example\EvidenceTest', 'second'),
            1,
            new TestArtifactBudget(),
        );
        $second->text('accepted.txt', 'x');

        Expect::that($second->collected())
            ->because('an overflow-safe test quota rejection MUST release its run quota')
            ->toHaveCount(1);
    }
}
