<?php

declare(strict_types=1);

namespace Greenlight\Coverage\Export;

use Greenlight\Coverage\CoverageMap;

/**
 * Cobertura XML output.
 *
 * Each file becomes one class element with per-line hit counts, and line-rate
 * attributes appear at class, package, and root level.
 *
 * Branch rates are reported as zero because only line coverage is collected.
 *
 * The timestamp is injected so output is deterministic and testable.
 *
 * @internal
 */
final readonly class CoberturaExporter implements CoverageExporter
{
    public const string FILE_NAME = 'cobertura.xml';

    public function __construct(private int $timestamp = 0) {}

    #[\Override]
    public function export(CoverageMap $map): array
    {
        $out = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $out .= \sprintf(
            '<coverage line-rate="%s" branch-rate="0" lines-covered="%d" lines-valid="%d" branches-covered="0" branches-valid="0" complexity="0" version="0" timestamp="%d">',
            $this->rate($map->coveredLineTotal(), $map->executableLineTotal()),
            $map->coveredLineTotal(),
            $map->executableLineTotal(),
            $this->timestamp,
        ) . "\n";
        $out .= '  <sources>' . "\n";
        $out .= '    <source>/</source>' . "\n";
        $out .= '  </sources>' . "\n";
        $out .= '  <packages>' . "\n";
        $out .= \sprintf(
            '    <package name="greenlight" line-rate="%s" branch-rate="0" complexity="0">',
            $this->rate($map->coveredLineTotal(), $map->executableLineTotal()),
        ) . "\n";
        $out .= '      <classes>' . "\n";

        foreach ($map->files() as $path => $file) {
            $out .= \sprintf(
                '        <class name="%s" filename="%s" line-rate="%s" branch-rate="0" complexity="0">',
                $this->xml(\ltrim($path, '/')),
                $this->xml(\ltrim($path, '/')),
                $this->rate($file->coveredLineCount(), $file->executableLineCount()),
            ) . "\n";
            $out .= '          <methods/>' . "\n";
            $out .= '          <lines>' . "\n";

            $hits = [];

            foreach ($file->coveredLines as $line) {
                $hits[$line] = 1;
            }

            foreach ($file->uncoveredLines as $line) {
                $hits[$line] = 0;
            }

            \ksort($hits);

            foreach ($hits as $line => $hit) {
                $out .= \sprintf('            <line number="%d" hits="%d"/>', $line, $hit) . "\n";
            }

            $out .= '          </lines>' . "\n";
            $out .= '        </class>' . "\n";
        }

        $out .= '      </classes>' . "\n";
        $out .= '    </package>' . "\n";
        $out .= '  </packages>' . "\n";
        $out .= '</coverage>' . "\n";

        return [self::FILE_NAME => $out];
    }

    private function rate(int $covered, int $executable): string
    {
        if ($executable === 0) {
            return '1.0000';
        }

        return \sprintf('%.4F', $covered / $executable);
    }

    private function xml(string $value): string
    {
        return \htmlspecialchars($value, \ENT_XML1 | \ENT_QUOTES, 'UTF-8');
    }
}
