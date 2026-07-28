<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Config;

use Greenlight\Attribute\Test;
use Greenlight\Config\ArtifactBuilder;
use Greenlight\Config\InvalidConfiguration;
use Greenlight\Expect\Expect;

final class ArtifactDirectoryValidationTest
{
    #[Test]
    public function nullBytesAreRejectedAtTheConfigurationBoundary(): void
    {
        Expect::that(static fn(): ArtifactBuilder => new ArtifactBuilder()->directory("artifacts\0hidden"))
            ->because('artifact directories MUST be valid file-system paths')
            ->toThrow(
                InvalidConfiguration::class,
                message: 'Artifact directory cannot contain a null byte.',
            );
    }
}
