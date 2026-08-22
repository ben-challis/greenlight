<?php

declare(strict_types=1);

namespace Greenlight\Cli\Command;

use Greenlight\Cli\Configuration\ConfigurationLoader;
use Greenlight\Cli\Input\ParsedArguments;
use Greenlight\Cli\Output\Console;
use Greenlight\Coverage\Diff\BaselineDiff;
use Greenlight\Coverage\Export\JsonExporter;
use Greenlight\Internal\Php\ErrorTrap;

/**
 * Compares saved baseline and current coverage maps.
 *
 * @internal
 */
final readonly class CoverageDiffCommand
{
    public function __construct(private Console $console) {}

    public function run(ParsedArguments $arguments, string $workingDirectory): int
    {
        $baselinePath = $arguments->value('baseline');
        $currentPath = $arguments->value('current');
        if ($baselinePath === null || $currentPath === null) {
            $this->console->err("coverage:diff requires --baseline=<path> and --current=<path>.\n");
            return 64;
        }
        $maps = [];
        foreach (['baseline' => $baselinePath, 'current' => $currentPath] as $label => $path) {
            $absolute = ConfigurationLoader::absolutePath($path, $workingDirectory);
            $json = ErrorTrap::run(static fn() => \file_get_contents($absolute), $warning);
            if ($json === false) {
                $this->console->error(\sprintf('Greenlight could not read the %s coverage export at "%s"%s.', $label, $path, $warning === null ? '' : ': ' . $warning), $arguments->has('no-ansi'));
                return 1;
            }
            try {
                $maps[$label] = JsonExporter::import($json);
            } catch (\Throwable $error) {
                $this->console->error(\sprintf('The %s file is not a valid coverage export: %s', $label, $error->getMessage()), $arguments->has('no-ansi'));
                return 1;
            }
        }
        $report = BaselineDiff::between($maps['baseline'], $maps['current']);
        $this->console->out(\sprintf("Coverage: baseline %.2f%%, current %.2f%% (%+.2f)\n", $report->baselinePercentage, $report->currentPercentage, $report->totalDelta()));
        foreach ($report->fileDeltas as $delta) {
            if ($delta->delta() === 0.0 && $delta->newlyUncoveredLines === []) {
                continue;
            }
            $line = \sprintf('%s: %s -> %s (%+.2f)', $delta->file, $delta->baselinePercentage === null ? 'absent' : \sprintf('%.2f%%', $delta->baselinePercentage), $delta->currentPercentage === null ? 'absent' : \sprintf('%.2f%%', $delta->currentPercentage), $delta->delta());
            if ($delta->newlyUncoveredLines !== []) {
                $line .= ', newly uncovered lines: ' . \implode(', ', $delta->newlyUncoveredLines);
            }
            $this->console->out($line . "\n");
        }
        if ($report->hasRegressions()) {
            $this->console->err("Coverage regressed against the baseline.\n");
            return 1;
        }
        return 0;
    }
}
