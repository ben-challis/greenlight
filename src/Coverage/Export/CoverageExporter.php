<?php

declare(strict_types=1);

namespace Greenlight\Coverage\Export;

use Greenlight\Coverage\CoverageMap;

/**
 * Renders a coverage map into one or more report documents.
 *
 * export() returns a map of relative file name to full content.
 * Single-document formats return exactly one entry; the HTML exporter returns
 * an index plus one page per covered file.
 *
 * Callers own writing the files to disk.
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
