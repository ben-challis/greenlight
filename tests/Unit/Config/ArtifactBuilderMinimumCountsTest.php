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

        Expect::that([
            $configuration->maxAttachmentsPerTest,
            $configuration->maxRunAttachments,
        ])
            ->because('artifact limits MUST accept their documented minimum')
            ->toBe([1, 1]);
    }
}
