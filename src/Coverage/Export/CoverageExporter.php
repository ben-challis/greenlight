<?php

declare(strict_types=1);

namespace Greenlight\Coverage\Export;

use Greenlight\Coverage\CoverageMap;

/**
 * export() gives pairs of relative file name and full content.
 * A single-document format gives exactly one pair. The HTML exporter gives an
 * index and one page for each file.
 *
 * An exporter can give the pairs from a generator. Then it creates each file
 * only when the caller asks for it. Callers write each file to disk before
 * they ask for the next file. Thus, memory holds one file at a time.
 *
 * @internal
 */
interface CoverageExporter
{
    /**
     * @return iterable<non-empty-string, string> relative file name => content
     */
    public function export(CoverageMap $map): iterable;
}
