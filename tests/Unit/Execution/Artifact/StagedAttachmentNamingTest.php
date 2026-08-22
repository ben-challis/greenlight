<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Execution\Artifact;

use Greenlight\Artifact\StagedAttachment;
use Greenlight\Attribute\Test;
use Greenlight\Config\ArtifactConfiguration;
use Greenlight\Execution\Artifact\ArtifactStore;
use Greenlight\Execution\Artifact\TestArtifactBudget;
use Greenlight\Expect\Expect;
use Greenlight\Sandbox\TemporaryDirectory;
use Greenlight\Test\Cleanup;
use Greenlight\Test\TestId;

final readonly class StagedAttachmentNamingTest
{
    public function __construct(
        private TemporaryDirectory $tempDirectory,
        private Cleanup $cleanup,
    ) {}

    #[Test]
    public function duplicateExtensionlessNamesGainANumericSuffix(): void
    {
        $root = $this->tempDirectory->subdirectory('extensionless-names');
        $store = ArtifactStore::open(new ArtifactConfiguration($root), $root, 'run-1');
        $this->cleanup->defer($store->cleanup(...));

        $attachments = $store->forAttempt(
            new TestId('Example\EvidenceTest', 'captures'),
            1,
            new TestArtifactBudget(),
        );
        $attachments->text('evidence', 'first');
        $attachments->text('evidence', 'second');
        $names = \array_map(
            static fn(StagedAttachment $attachment): string => \basename($attachment->storageKey),
            $attachments->seal(),
        );

        Expect::that($names)
            ->because('duplicate extensionless attachment names MUST remain distinct')
            ->toBe(['01-evidence', '02-evidence-2']);
    }
}
