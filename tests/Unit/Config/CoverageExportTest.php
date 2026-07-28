<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Config;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Config\CoverageExport;
use Greenlight\Config\InvalidConfiguration;
use Greenlight\Expect\Expect;

final class CoverageExportTest
{
    #[Test]
    #[DataSet('incompleteExports')]
    public function rejectsAnIncompleteExport(string $format, string $target): void
    {
        Expect::that(static fn(): CoverageExport => new CoverageExport($format, $target))
            ->because('a coverage export MUST identify its format and target')
            ->toThrow(
                InvalidConfiguration::class,
                message: 'Coverage exports need a non-empty format and target.',
            );
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function incompleteExports(): iterable
    {
        yield 'empty format' => ['', 'coverage.json'];
        yield 'empty target' => ['json', ''];
    }
}
