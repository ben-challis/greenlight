<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Config;

use Greenlight\Attribute\Test;
use Greenlight\Config\ArtifactConfiguration;
use Greenlight\Expect\Expect;

final class ArtifactConfigurationCopyTest
{
    #[Test]
    public function withDirectoryReplacesOnlyTheOutputDirectory(): void
    {
        $original = new ArtifactConfiguration(
            directory: 'build/original-artifacts',
            maxAttachmentsPerTest: 2,
            maxAttachmentBytes: 3,
            maxTestBytes: 5,
            maxRunAttachments: 7,
            maxRunBytes: 11,
        );
        $expected = $original->toWire();
        $expected['directory'] = 'build/reconfigured-artifacts';

        $reconfigured = $original->withDirectory('build/reconfigured-artifacts');

        Expect::that($reconfigured)
            ->because('changing the artifact directory MUST produce a replacement configuration')
            ->not()
            ->toBe($original)
            ->and($original->directory)
            ->because('changing the artifact directory MUST NOT change the original configuration')
            ->toBe('build/original-artifacts')
            ->and($reconfigured->toWire())
            ->because('the replacement MUST preserve every artifact safety limit')
            ->toBe($expected);
    }
}
