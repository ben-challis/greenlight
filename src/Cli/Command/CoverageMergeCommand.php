<?php

declare(strict_types=1);

namespace Greenlight\Cli\Command;

use Greenlight\Cli\Configuration\ConfigurationLoader;
use Greenlight\Cli\Configuration\CoverageOverrides;
use Greenlight\Cli\Coverage\CoverageWriter;
use Greenlight\Cli\Input\CliError;
use Greenlight\Cli\Input\ParsedArguments;
use Greenlight\Cli\Output\Console;
use Greenlight\Command\ExitCode;
use Greenlight\Config\CoverageConfiguration;
use Greenlight\Config\CoverageExport;
use Greenlight\Config\InvalidConfiguration;
use Greenlight\Coverage\CoverageMap;
use Greenlight\Coverage\Diff\ProjectRootNormalizer;
use Greenlight\Coverage\Export\JsonExporter;
use Greenlight\Internal\Php\ErrorTrap;
use Greenlight\Reporting\Style;

/**
 * Merges saved coverage maps and writes the selected coverage exports.
 *
 * @internal
 */
final readonly class CoverageMergeCommand
{
    public function __construct(private Console $console) {}

    public function run(ParsedArguments $arguments, string $workingDirectory): ExitCode
    {
        $inputs = $arguments->values('input');

        if (\count($inputs) < 2) {
            $this->console->err("coverage:merge requires at least two --input=<path> options.\n");

            return ExitCode::usage();
        }

        try {
            $exports = $this->exports($arguments, $workingDirectory);
            $coverageOverrides = CoverageOverrides::fromArguments($arguments);
        } catch (CliError|InvalidConfiguration $error) {
            $this->console->error($error->getMessage(), $arguments->has('no-ansi'));

            return ExitCode::usage();
        }

        if ($exports === []) {
            $this->console->err("coverage:merge requires at least one --export=<format>=<path> option.\n");

            return ExitCode::usage();
        }

        $inputRoots = $arguments->values('input-root');
        $projectRoot = $arguments->value('project-root');

        if (($inputRoots === []) !== ($projectRoot === null)) {
            $this->console->err("Use --input-root=<path> and --project-root=<path> together.\n");

            return ExitCode::usage();
        }

        if ($inputRoots !== [] && \count($inputRoots) !== \count($inputs)) {
            $this->console->err("Repeat --input-root=<path> once for each --input=<path>.\n");

            return ExitCode::usage();
        }

        $targetRoot = $projectRoot === null
            ? null
            : ConfigurationLoader::absolutePath($projectRoot, $workingDirectory);
        $merged = CoverageMap::empty();

        /** @var array<string, string|null> $seenInputs */
        $seenInputs = [];

        foreach ($inputs as $index => $input) {
            if ($input === '') {
                $this->console->err("--input requires a non-empty path.\n");

                return ExitCode::usage();
            }

            $path = ConfigurationLoader::absolutePath($input, $workingDirectory);
            $realPath = \realpath($path);
            $identity = $realPath === false ? $path : $realPath;
            $inputRoot = $inputRoots === []
                ? null
                : ConfigurationLoader::absolutePath($inputRoots[$index], $workingDirectory);
            $rootIdentity = $inputRoot === null ? null : $this->rootIdentity($inputRoot);

            if (\array_key_exists($identity, $seenInputs)) {
                if ($seenInputs[$identity] !== $rootIdentity) {
                    $this->console->error(\sprintf(
                        'Coverage input "%s" cannot use more than one input root.',
                        $input,
                    ), $arguments->has('no-ansi'));

                    return ExitCode::usage();
                }

                continue;
            }

            $seenInputs[$identity] = $rootIdentity;
            $json = ErrorTrap::run(static fn() => \file_get_contents($path), $warning);

            if (!\is_string($json)) {
                $this->console->error(\sprintf(
                    'Greenlight could not read coverage input "%s"%s.',
                    $input,
                    $warning === null ? '' : ': ' . $warning,
                ), $arguments->has('no-ansi'));

                return ExitCode::failure();
            }

            try {
                $map = JsonExporter::import($json);

                if ($inputRoot !== null && $targetRoot !== null) {
                    $map = ProjectRootNormalizer::relocate($map, $inputRoot, $targetRoot);
                } else {
                    $this->requireAbsolutePaths($map);
                }
            } catch (\Throwable $error) {
                $this->console->error(\sprintf(
                    'Coverage input "%s" is not compatible: %s',
                    $input,
                    $error->getMessage(),
                ), $arguments->has('no-ansi'));

                return ExitCode::failure();
            }

            $merged = $merged->merge($map);
        }

        $configuration = new CoverageConfiguration(
            [],
            null,
            $exports,
            $coverageOverrides->minimumPercentage,
            $coverageOverrides->maximumUncoveredLines,
        );
        $style = new Style($this->console->capabilities(
            $arguments->has('no-ansi'),
            $arguments->has('ansi'),
        )->color);

        return new CoverageWriter($this->console)->write(
            $configuration,
            $merged,
            $workingDirectory,
            $style,
        ) ? ExitCode::success() : ExitCode::failure();
    }

    /**
     * @return list<CoverageExport>
     * @throws CliError
     * @throws InvalidConfiguration
     */
    private function exports(ParsedArguments $arguments, string $workingDirectory): array
    {
        $exports = [];
        $targets = [];

        foreach ($arguments->values('export') as $raw) {
            $separator = \strpos($raw, '=');

            if ($separator === false) {
                throw CliError::malformedCoverageExport($raw);
            }

            $export = new CoverageExport(
                \substr($raw, 0, $separator),
                \substr($raw, $separator + 1),
            );
            $target = ConfigurationLoader::absolutePath($export->target, $workingDirectory);

            if (isset($targets[$target])) {
                throw CliError::duplicateCoverageExport($export->target);
            }

            $targets[$target] = true;
            $exports[] = $export;
        }

        return $exports;
    }

    private function rootIdentity(string $root): string
    {
        return $root === '/' ? '/' : \rtrim($root, '/');
    }

    private function requireAbsolutePaths(CoverageMap $map): void
    {
        foreach ($map->files() as $path => $_coverage) {
            if (!\str_starts_with($path, '/')) {
                throw new \InvalidArgumentException(\sprintf(
                    'Coverage JSON requires an absolute file path. Received "%s".',
                    $path,
                ));
            }
        }
    }
}
