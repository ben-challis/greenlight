<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Coverage\Export;

use Greenlight\Attribute\Test;
use Greenlight\Coverage\CoverageMap;
use Greenlight\Coverage\Export\LcovExporter;
use Greenlight\Coverage\FileCoverage;
use Greenlight\Expect\Expect;

final class LcovNullPathValidationTest
{
    #[Test]
    public function nullBytesAreRejectedBeforeWritingTheSourceRecord(): void
    {
        $map = new CoverageMap([
            new FileCoverage("/src/A\0hidden.php", [1], []),
        ]);

        Expect::that(static fn(): array => new LcovExporter()->export($map))
            ->because('LCOV source records MUST contain valid file paths')
            ->toThrow(
                \InvalidArgumentException::class,
                message: 'LCOV file paths MUST NOT contain null bytes.',
            );
    }
}
