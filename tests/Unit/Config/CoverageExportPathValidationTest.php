<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Config;

use Greenlight\Attribute\Test;
use Greenlight\Config\CoverageBuilder;
use Greenlight\Config\InvalidConfiguration;
use Greenlight\Expect\Expect;

final class CoverageExportPathValidationTest
{
    #[Test]
    public function nullBytesAreRejectedAtTheConfigurationBoundary(): void
    {
        Expect::that(static fn(): CoverageBuilder => new CoverageBuilder()->export('json', "coverage\0hidden.json"))
            ->because('coverage export targets MUST be valid file-system paths')
            ->toThrow(
                InvalidConfiguration::class,
                message: 'Coverage export target cannot contain a null byte.',
            );
    }
}
