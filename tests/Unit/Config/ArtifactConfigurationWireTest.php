<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Config;

use Greenlight\Attribute\Test;
use Greenlight\Config\ArtifactConfiguration;
use Greenlight\Expect\Expect;
use Greenlight\Tests\Support\JsonWire;

final class ArtifactConfigurationWireTest
{
    #[Test]
    public function everyArtifactLimitSurvivesAJsonWireRoundTrip(): void
    {
        $configuration = new ArtifactConfiguration(
            directory: 'custom/artifacts',
            maxAttachmentsPerTest: 11,
            maxAttachmentBytes: 22,
            maxTestBytes: 33,
            maxRunAttachments: 44,
            maxRunBytes: 55,
        );

        $restored = ArtifactConfiguration::fromWire(
            JsonWire::roundTrip($configuration->toWire()),
        );

        Expect::that($restored)
            ->because('workers MUST receive every configured artifact safety limit')
            ->toEqual($configuration);
    }
}
