<?php

declare(strict_types=1);

namespace Greenlight\Coverage\Export;

use Greenlight\Coverage\CoverageMap;

/**
 * Each file becomes one class element with a hit count for each line.
 * Class, package, and root elements contain line-rate attributes.
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
        $branchAttributes = $map->branchCoverage ? \sprintf(
            ' branch-rate="%s" branches-covered="%d" branches-valid="%d"',
            $this->rate($map->coveredBranchTotal(), $map->branchTotal()),
            $map->coveredBranchTotal(),
            $map->branchTotal(),
        ) : '';
        $out .= \sprintf(
            '<coverage line-rate="%s"%s lines-covered="%d" lines-valid="%d" complexity="0" version="0" timestamp="%d">',
            $this->rate($map->coveredLineTotal(), $map->executableLineTotal()),
            $branchAttributes,
            $map->coveredLineTotal(),
            $map->executableLineTotal(),
            $this->timestamp,
        ) . "\n";
        $out .= '  <sources>' . "\n";
        $out .= '    <source>/</source>' . "\n";
        $out .= '  </sources>' . "\n";
        $out .= '  <packages>' . "\n";
        $out .= \sprintf(
            '    <package name="greenlight" line-rate="%s"%s complexity="0">',
            $this->rate($map->coveredLineTotal(), $map->executableLineTotal()),
            $map->branchCoverage ? ' branch-rate="' . $this->rate($map->coveredBranchTotal(), $map->branchTotal()) . '"' : '',
        ) . "\n";
        $out .= '      <classes>' . "\n";

        foreach ($map->files() as $path => $file) {
            $out .= \sprintf(
                '        <class name="%s" filename="%s" line-rate="%s"%s complexity="0">',
                XmlEscaper::attribute(\ltrim($path, '/')),
                XmlEscaper::attribute(\ltrim($path, '/')),
                $this->rate($file->coveredLineCount(), $file->executableLineCount()),
                $map->branchCoverage ? ' branch-rate="' . $this->rate($file->coveredBranchTotal(), $file->branchTotal()) . '"' : '',
            ) . "\n";
            $out .= '          <methods/>' . "\n";
            $out .= '          <lines>' . "\n";

            $lineHits = $file->lineHits();
            $branchesByLine = $file->branchesByLine();
            $lines = \array_unique([...\array_keys($lineHits), ...\array_keys($branchesByLine)]);
            \sort($lines);

            foreach ($lines as $line) {
                $branches = $branchesByLine[$line] ?? [];
                $hit = $lineHits[$line] ?? (\array_filter($branches, static fn($branch): bool => $branch->covered) === [] ? 0 : 1);

                if ($branches === []) {
                    $out .= \sprintf('            <line number="%d" hits="%d"/>', $line, $hit) . "\n";
                    continue;
                }

                $covered = \count(\array_filter($branches, static fn($branch): bool => $branch->covered));
                $total = \count($branches);
                $percentage = $total === 0 ? 100 : (int) \round($covered / $total * 100);
                $out .= \sprintf(
                    '            <line number="%d" hits="%d" branch="true" condition-coverage="%d%% (%d/%d)">',
                    $line,
                    $hit,
                    $percentage,
                    $covered,
                    $total,
                ) . "\n";
                $out .= '              <conditions>' . "\n";

                foreach ($branches as $branch) {
                    $out .= \sprintf(
                        '                <condition number="%d" type="jump" coverage="%d%%"/>',
                        $branch->id,
                        $branch->covered ? 100 : 0,
                    ) . "\n";
                }

                $out .= '              </conditions>' . "\n";
                $out .= '            </line>' . "\n";
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
