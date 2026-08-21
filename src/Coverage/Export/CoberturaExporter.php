<?php

declare(strict_types=1);

namespace Greenlight\Coverage\Export;

use Greenlight\Coverage\CoverageMap;

/**
 * Each file becomes one class element with a hit count for each line.
 * Class, package, and root elements contain line-rate attributes.
 *
 * The report uses zero for branch rates because Greenlight collects only line coverage.
 *
 * The caller supplies the timestamp to make the output deterministic.
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
                XmlEscaper::attribute(\ltrim($path, '/')),
                XmlEscaper::attribute(\ltrim($path, '/')),
                $this->rate($file->coveredLineCount(), $file->executableLineCount()),
            ) . "\n";
            $out .= '          <methods/>' . "\n";
            $out .= '          <lines>' . "\n";

            foreach ($file->lineHits() as $line => $hit) {
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
}
