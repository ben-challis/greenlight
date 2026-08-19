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

    #[Test]
    #[DataSet('unknownFormats')]
    public function rejectsAnUnknownFormat(string $format): void
    {
        Expect::that(static fn(): CoverageExport => new CoverageExport($format, 'coverage.out'))
            ->because('coverage exports MUST use a format that Greenlight can write')
            ->toThrow(
                InvalidConfiguration::class,
                message: \sprintf(
                    'Unknown coverage export format "%s". Use "json", "lcov", "clover", "cobertura", or "html".',
                    $format,
                ),
            );
    }

    #[Test]
    public function preservesANonemptyZeroStringTarget(): void
    {
        $export = new CoverageExport('json', '0');

        Expect::that($export->format)
            ->toBe('json');
        Expect::that($export->target)
            ->because('a zero-string coverage export target is not empty')
            ->toBe('0');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unknownFormats(): iterable
    {
        yield 'zero string' => ['0'];
        yield 'misspelled JSON' => ['jsno'];
        yield 'uppercase HTML' => ['HTML'];
    }
}
