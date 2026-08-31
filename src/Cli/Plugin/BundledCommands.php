<?php

declare(strict_types=1);

namespace Greenlight\Cli\Plugin;

use Greenlight\Cli\Command\CompletionCommand;
use Greenlight\Cli\Command\CoverageDiffCommand;
use Greenlight\Cli\Command\CoverageMergeCommand;
use Greenlight\Cli\Command\IdeHelperCommand;
use Greenlight\Cli\Command\ListCommand;
use Greenlight\Cli\Command\ProfileReportCommand;
use Greenlight\Cli\Input\CliError;
use Greenlight\Cli\Input\Definition;
use Greenlight\Cli\Output\Console;
use Greenlight\Cli\Output\ExitCode;
use Greenlight\Cli\Run\ArtifactsPruneCommand;
use Greenlight\Cli\Run\RunCommand;
use Greenlight\Coverage\CoverageError;
use Greenlight\Plugin\CommandDefinition;
use Greenlight\Plugin\CommandInvocation;
use Greenlight\Plugin\CommandProvider;
use Greenlight\Reporting\ReportGenerationFailed;

/**
 * Supplies the commands that Greenlight includes.
 *
 * @internal
 */
final readonly class BundledCommands implements CommandProvider
{
    /** @param non-empty-string $version */
    public function __construct(
        private Console $console,
        private string $version,
        private Definition $definition = new Definition(),
    ) {}

    /** @return list<CommandDefinition> */
    #[\Override]
    public function commands(): array
    {
        $definitions = [];

        foreach (Definition::COMMAND_DESCRIPTIONS as $name => $description) {
            $definitions[] = new CommandDefinition(
                $name,
                $description,
                $name === 'completion'
                    ? $this->completion(...)
                    : $this->parsedCommand(...),
            );
        }

        return $definitions;
    }

    private function completion(CommandInvocation $invocation): int
    {
        return new CompletionCommand($this->console, $this->definition)->run($invocation->arguments);
    }

    /**
     * @throws CoverageError
     * @throws ReportGenerationFailed
     */
    private function parsedCommand(CommandInvocation $invocation): int
    {
        try {
            $arguments = $this->definition->parser()->parse($invocation->argv());
        } catch (CliError $error) {
            $this->console->error($error->getMessage(), \in_array('--no-ansi', $invocation->argv(), true));

            return ExitCode::USAGE;
        }

        if ($arguments->has('help')) {
            $this->console->out(Definition::HELP . "\n");

            return ExitCode::SUCCESS;
        }

        if ($arguments->has('version')) {
            $this->console->out('Greenlight ' . $this->version . "\n");

            return ExitCode::SUCCESS;
        }

        $command = $arguments->command ?? 'run';

        if ($arguments->has('format')
            && $command !== 'list-tests'
            && ($command !== 'run' || !$arguments->has('list-tests'))
        ) {
            $this->console->error(
                CliError::formatRequiresTestListing()->getMessage(),
                $arguments->has('no-ansi'),
            );

            return ExitCode::USAGE;
        }

        if ($command === 'list-tests'
            || ($command === 'run'
                && !$arguments->has('dry-run')
                && ($arguments->has('list-tests') || $arguments->has('list-groups') || $arguments->has('list-suites')))
        ) {
            return new ListCommand($this->console)->run($arguments, $invocation->workingDirectory);
        }

        return match ($command) {
            'run' => new RunCommand($this->console, $this->version)->run(
                $arguments,
                $invocation->workingDirectory,
                $invocation->binaryPath,
            ),
            'coverage:merge' => new CoverageMergeCommand($this->console)->run($arguments, $invocation->workingDirectory),
            'coverage:diff' => new CoverageDiffCommand($this->console)->run($arguments, $invocation->workingDirectory),
            'profile:report' => new ProfileReportCommand($this->console)->run($arguments, $invocation->workingDirectory),
            'artifacts:prune' => new ArtifactsPruneCommand($this->console)->run($arguments, $invocation->workingDirectory),
            'ide-helper' => new IdeHelperCommand($this->console)->run($arguments, $invocation->workingDirectory),
            default => throw new \LogicException(\sprintf('Bundled command "%s" has no handler.', $command)),
        };
    }
}
