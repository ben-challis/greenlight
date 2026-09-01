<?php

declare(strict_types=1);

namespace Greenlight\Cli\Command;

use Greenlight\Cli\Configuration\ConfigurationLoader;
use Greenlight\Cli\Configuration\CoverageOverrides;
use Greenlight\Cli\Coverage\CoverageGate;
use Greenlight\Cli\Input\CliError;
use Greenlight\Cli\Input\ParsedArguments;
use Greenlight\Cli\Output\Console;
use Greenlight\Command\CommandResult;
use Greenlight\Config\CoverageConfiguration;
use Greenlight\Coverage\Diff\BaselineDiff;
use Greenlight\Coverage\Diff\ProjectRootNormalizer;
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

    public function run(ParsedArguments $arguments, string $workingDirectory): CommandResult
    {
        try {
            $coverageOverrides = CoverageOverrides::fromArguments($arguments);
        } catch (CliError $error) {
            $this->console->error($error->getMessage(), $arguments->has('no-ansi'));

            return CommandResult::usage();
        }

        $baselinePath = $arguments->value('baseline');
        $currentPath = $arguments->value('current');
        if ($baselinePath === null || $currentPath === null) {
            $this->console->err("coverage:diff requires --baseline=<path> and --current=<path>.\n");
            return CommandResult::usage();
        }
        $baselineRoot = $arguments->value('baseline-root');
        $currentRoot = $arguments->value('current-root');
        if (($baselineRoot === null) !== ($currentRoot === null)) {
            $this->console->err("Use --baseline-root=<path> and --current-root=<path> together.\n");
            return CommandResult::usage();
        }
        $maps = [];
        foreach (['baseline' => $baselinePath, 'current' => $currentPath] as $label => $path) {
            $absolute = ConfigurationLoader::absolutePath($path, $workingDirectory);
            $json = ErrorTrap::run(static fn() => \file_get_contents($absolute), $warning);
            if ($json === false) {
                $this->console->error(
                    \sprintf(
                        'Greenlight could not read the %s coverage export at "%s"%s.',
                        $label,
                        $path,
                        $warning === null ? '' : ': ' . $warning,
                    ),
                    $arguments->has('no-ansi'),
                );

                return CommandResult::failure();
            }
            try {
                $maps[$label] = JsonExporter::import($json);
            } catch (\Throwable $error) {
                $this->console->error(
                    \sprintf(
                        'The %s file is not a valid coverage export: %s',
                        $label,
                        $error->getMessage(),
                    ),
                    $arguments->has('no-ansi'),
                );

                return CommandResult::failure();
            }
        }

        if ($baselineRoot !== null && $currentRoot !== null) {
            foreach (['baseline' => $baselineRoot, 'current' => $currentRoot] as $label => $root) {
                try {
                    $maps[$label] = ProjectRootNormalizer::normalize(
                        $maps[$label],
                        ConfigurationLoader::absolutePath($root, $workingDirectory),
                    );
                } catch (\InvalidArgumentException $error) {
                    $this->console->error(\sprintf(
                        'The %s coverage export cannot use --%s-root: %s',
                        $label,
                        $label,
                        $error->getMessage(),
                    ), $arguments->has('no-ansi'));

                    return CommandResult::failure();
                }
            }
        }
        $report = BaselineDiff::between($maps['baseline'], $maps['current']);
        $this->console->out(
            \sprintf(
                "Coverage: baseline %.2f%%, current %.2f%% (%+.2f)\n",
                $report->baselinePercentage,
                $report->currentPercentage,
                $report->totalDelta(),
            ),
        );
        foreach ($report->fileDeltas as $delta) {
            if ($delta->delta() === 0.0 && $delta->newlyUncoveredLines === []) {
                continue;
            }
            $line = \sprintf(
                '%s: %s -> %s (%+.2f)',
                $delta->file,
                $delta->baselinePercentage === null ? 'absent' : \sprintf('%.2f%%', $delta->baselinePercentage),
                $delta->currentPercentage === null ? 'absent' : \sprintf('%.2f%%', $delta->currentPercentage),
                $delta->delta(),
            );
            if ($delta->newlyUncoveredLines !== []) {
                $line .= ', newly uncovered lines: ' . \implode(', ', $delta->newlyUncoveredLines);
            }
            $this->console->out($line . "\n");
        }

        $gateFailures = CoverageGate::failures(
            new CoverageConfiguration(
                [],
                null,
                [],
                $coverageOverrides->minimumPercentage,
                $coverageOverrides->maximumUncoveredLines,
            ),
            $maps['current'],
        );

        foreach ($gateFailures as $failure) {
            $this->console->err($failure . "\n");
        }

        if ($report->hasRegressions()) {
            $this->console->err("Coverage regressed against the baseline.\n");
        }

        return $report->hasRegressions() || $gateFailures !== []
            ? CommandResult::failure()
            : CommandResult::success();
    }
}
