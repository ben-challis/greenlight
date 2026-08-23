<?php

declare(strict_types=1);

namespace Greenlight\Cli\Command;

use Greenlight\Cli\Input\CliError;
use Greenlight\Cli\Input\Definition;
use Greenlight\Cli\Output\Console;
use Greenlight\Cli\Run\RunCommand;
use Greenlight\Coverage\CoverageError;
use Greenlight\Internal\Wire\WireCommunicationFailed;
use Greenlight\Reporting\ReportGenerationFailed;

/**
 * Parses CLI input and dispatches each supported command explicitly.
 *
 * @internal
 */
final readonly class CommandDispatcher
{
    /** @param non-empty-string $version */
    public function __construct(private Console $console, private string $version, private Definition $definition = new Definition()) {}

    /**
     * @param list<string> $argv
     * @throws CoverageError
     * @throws ReportGenerationFailed
     * @throws WireCommunicationFailed
     */
    public function dispatch(array $argv, string $workingDirectory, ?string $binPath): int
    {
        if (($argv[0] ?? null) === 'completion') {
            return new CompletionCommand($this->console, $this->definition)->run(\array_slice($argv, 1));
        }
        try {
            $arguments = $this->definition->parser()->parse($argv);
        } catch (CliError $error) {
            $this->console->error($error->getMessage(), \in_array('--no-ansi', $argv, true));
            return 64;
        }
        if ($arguments->has('help')) {
            $this->console->out(Definition::HELP . "\n");
            return 0;
        }
        if ($arguments->has('version')) {
            $this->console->out('Greenlight ' . $this->version . "\n");
            return 0;
        }
        $command = $arguments->command ?? 'run';
        if ($arguments->has('format')
            && $command !== 'list-tests'
            && ($command !== 'run' || !$arguments->has('list-tests'))
        ) {
            $this->console->error(CliError::formatRequiresTestListing()->getMessage(), $arguments->has('no-ansi'));
            return 64;
        }
        if ($command === 'list-tests' || ($command === 'run' && !$arguments->has('dry-run') && ($arguments->has('list-tests') || $arguments->has('list-groups') || $arguments->has('list-suites')))) {
            return new ListCommand($this->console)->run($arguments, $workingDirectory);
        }
        return match ($command) {
            'run' => new RunCommand($this->console, $this->version)->run($arguments, $workingDirectory, $binPath),
            'coverage:diff' => new CoverageDiffCommand($this->console)->run($arguments, $workingDirectory),
            'profile:report' => new ProfileReportCommand($this->console)->run($arguments, $workingDirectory),
            'ide-helper' => new IdeHelperCommand($this->console)->run($arguments, $workingDirectory),
            default => $this->unknown($command, $arguments->has('no-ansi')),
        };
    }

    private function unknown(string $command, bool $noAnsi): int
    {
        $this->console->error(\sprintf("Unknown command '%s'. Use greenlight --help to list commands.", $command), $noAnsi);
        return 64;
    }
}
