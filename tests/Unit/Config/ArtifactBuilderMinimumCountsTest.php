<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Config;

use Greenlight\Attribute\Test;
use Greenlight\Config\ArtifactBuilder;
use Greenlight\Expect\Expect;

final class ArtifactBuilderMinimumCountsTest
{
    #[Test]
    public function oneAttachmentIsAValidSafetyLimit(): void
    {
        $configuration = new ArtifactBuilder()
            ->maxAttachmentsPerTest(1)
            ->maxRunAttachments(1)
            ->toConfiguration();

        Expect::that($configuration->maxAttachmentsPerTest)
            ->because('the per-test artifact limit MUST accept its documented minimum')
            ->toBe(1);
        Expect::that($configuration->maxRunAttachments)
            ->because('the per-run artifact limit MUST accept its documented minimum')
            ->toBe(1);
    }
}
