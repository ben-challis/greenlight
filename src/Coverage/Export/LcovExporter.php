<?php

declare(strict_types=1);

namespace Greenlight\Coverage\Export;

use Greenlight\Coverage\CoverageMap;

/**
 * lcov tracefile output, the .info format read by genhtml and most coverage
 * services.
 *
 * Each file produces one SF record, a DA record per executable line with a
 * hit count of one or zero, then LF and LH line totals.
 *
 * @internal
 */
final readonly class LcovExporter implements CoverageExporter
{
    public const string FILE_NAME = 'lcov.info';

    #[\Override]
    public function export(CoverageMap $map): array
    {
        $out = '';

        foreach ($map->files() as $path => $file) {
            $out .= 'SF:' . $path . "\n";

            $hits = [];

            foreach ($file->coveredLines as $line) {
                $hits[$line] = 1;
            }

            foreach ($file->uncoveredLines as $line) {
                $hits[$line] = 0;
            }

            \ksort($hits);

            foreach ($hits as $line => $hit) {
                $out .= 'DA:' . $line . ',' . $hit . "\n";
            }

            $out .= 'LF:' . $file->executableLineCount() . "\n";
            $out .= 'LH:' . $file->coveredLineCount() . "\n";
            $out .= "end_of_record\n";
        }

        return [self::FILE_NAME => $out];
    }
}
