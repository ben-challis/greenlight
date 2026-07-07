<?php

declare(strict_types=1);

namespace Greenlight\Coverage\Export;

use Greenlight\Coverage\CoverageMap;

/**
 * Clover XML output. Every executable line becomes a stmt line element with
 * a count of one or zero, and file plus project metrics carry statement
 * totals. The timestamp is injected so output is deterministic and testable.
 *
 * @internal
 */
final readonly class CloverExporter implements CoverageExporter
{
    public const string FILE_NAME = 'clover.xml';

    public function __construct(
        private int $timestamp = 0,
    ) {}

    #[\Override]
    public function export(CoverageMap $map): array
    {
        $out = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $out .= \sprintf('<coverage generated="%d">', $this->timestamp) . "\n";
        $out .= \sprintf('  <project timestamp="%d" name="greenlight">', $this->timestamp) . "\n";

        foreach ($map->files() as $path => $file) {
            $out .= \sprintf('    <file name="%s">', $this->xml($path)) . "\n";

            $counts = [];

            foreach ($file->coveredLines as $line) {
                $counts[$line] = 1;
            }

            foreach ($file->uncoveredLines as $line) {
                $counts[$line] = 0;
            }

            \ksort($counts);

            foreach ($counts as $line => $count) {
                $out .= \sprintf('      <line num="%d" type="stmt" count="%d"/>', $line, $count) . "\n";
            }

            $out .= '      ' . $this->metrics($file->executableLineCount(), $file->coveredLineCount()) . "\n";
            $out .= '    </file>' . "\n";
        }

        $out .= '    ' . $this->metrics($map->executableLineTotal(), $map->coveredLineTotal(), \count($map->files())) . "\n";
        $out .= '  </project>' . "\n";
        $out .= '</coverage>' . "\n";

        return [self::FILE_NAME => $out];
    }

    private function metrics(int $statements, int $covered, ?int $files = null): string
    {
        $prefix = $files === null ? '' : \sprintf('files="%d" ', $files);

        return \sprintf(
            '<metrics %sloc="0" ncloc="0" classes="0" methods="0" coveredmethods="0" conditionals="0" coveredconditionals="0" statements="%d" coveredstatements="%d" elements="%d" coveredelements="%d"/>',
            $prefix,
            $statements,
            $covered,
            $statements,
            $covered,
        );
    }

    private function xml(string $value): string
    {
        return \htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
