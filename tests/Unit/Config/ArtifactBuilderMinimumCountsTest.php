<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Config;

use Greenlight\Attribute\Test;
use Greenlight\Config\ArtifactBuilder;

use function Greenlight\expect;

final class ArtifactBuilderMinimumCountsTest
{
    #[Test]
    public function oneAttachmentIsAValidSafetyLimit(): void
    {
        $configuration = new ArtifactBuilder()
            ->maxAttachmentsPerTest(1)
            ->maxRunAttachments(1)
            ->toConfiguration();

        expect($configuration->maxAttachmentsPerTest)
            ->because('the per-test artifact limit MUST accept its documented minimum')
            ->toBe(1);
        expect($configuration->maxRunAttachments)
            ->because('the per-run artifact limit MUST accept its documented minimum')
            ->toBe(1);
    }
}
