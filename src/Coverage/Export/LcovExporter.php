<?php

declare(strict_types=1);

namespace Greenlight\Coverage\Export;

use Greenlight\Coverage\CoverageMap;

/**
 * Each file produces one SF record and one DA record for each executable
 * line. A DA record has a hit count of one or zero. LF and LH contain line
 * totals. Each SF record stays on one line. A source path cannot contain a
 * line break.
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
            if (\strpbrk($path, "\r\n") !== false) {
                throw new \InvalidArgumentException('LCOV file paths MUST NOT contain line breaks.');
            }

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
