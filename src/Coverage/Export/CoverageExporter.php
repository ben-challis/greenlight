<?php

declare(strict_types=1);

namespace Greenlight\Coverage\Export;

use Greenlight\Coverage\CoverageMap;

/**
 * export() returns a map of relative file name to full content.
 * A single-document format returns exactly one entry. The HTML exporter
 * returns an index and one page for each covered file.
 *
 * Callers write the files to disk.
 *
 * @internal
 */
interface CoverageExporter
{
    /**
     * @return non-empty-array<non-empty-string, string> relative file name => content
     */
    public function export(CoverageMap $map): array;
}
