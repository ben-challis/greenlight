<?php

declare(strict_types=1);

namespace Greenlight\Cli\Command;

use Greenlight\Cli\Configuration\ConfigurationLoader;
use Greenlight\Cli\Discovery\SelectionDiscovery;
use Greenlight\Cli\Discovery\SelectionPlan;
use Greenlight\Cli\Input\CliError;
use Greenlight\Cli\Input\ParsedArguments;
use Greenlight\Cli\Output\Console;
use Greenlight\Command\CommandResult;
use Greenlight\Config\ConfigFileError;
use Greenlight\Config\InvalidConfiguration;
use Greenlight\Config\ResolvedConfiguration;
use Greenlight\Config\SuiteConfiguration;
use Greenlight\Discovery\DiscoveryError;
use Greenlight\Discovery\Plan\ExecutionPlan;

/**
 * Discovers and formats test, group, and suite listings.
 *
 * @internal
 */
final readonly class ListCommand
{
    public function __construct(private Console $console) {}

    public function run(ParsedArguments $arguments, string $workingDirectory): CommandResult
    {
        $format = $arguments->value('format') ?? 'text';

        if (!\in_array($format, ['text', 'json'], true)) {
            $this->console->error(CliError::unknownTestListFormat($format)->getMessage(), $arguments->has('no-ansi'));
            return CommandResult::usage();
        }

        $manifest = $format === 'json';

        if ($manifest && ($arguments->has('list-groups') || $arguments->has('list-suites'))) {
            $this->console->error(CliError::formatRequiresTestListing()->getMessage(), $arguments->has('no-ansi'));
            return CommandResult::usage();
        }

        if (!$manifest) {
            return $this->execute($arguments, $workingDirectory, false);
        }

        \ob_start();

        try {
            return $this->execute($arguments, $workingDirectory, true);
        } finally {
            $diagnostics = \ob_get_clean();

            if (\is_string($diagnostics) && $diagnostics !== '') {
                $this->console->err($diagnostics);
            }
        }
    }

    private function execute(ParsedArguments $arguments, string $workingDirectory, bool $manifest): CommandResult
    {
        try {
            $loaded = new ConfigurationLoader()->load($arguments, $workingDirectory);
        } catch (CliError $error) {
            $this->console->error($error->getMessage(), $arguments->has('no-ansi'));
            return CommandResult::usage();
        } catch (ConfigFileError|InvalidConfiguration $error) {
            $this->console->error($error->getMessage(), $arguments->has('no-ansi'));
            return CommandResult::failure();
        }
        $standalone = $arguments->command === 'list-tests';
        if (!$standalone && $arguments->has('list-suites')) {
            return $this->suites($loaded->resolved);
        }
        $discovery = new SelectionDiscovery($loaded, $workingDirectory);
        $this->warnWhenExcludePathsMatchNothing($discovery, $arguments->has('no-ansi'));
        try {
            $plan = SelectionPlan::resolve($loaded, $workingDirectory, $arguments->has('failed'));
        } catch (CliError $error) {
            $this->console->error($error->getMessage(), $arguments->has('no-ansi'));
            return CommandResult::usage();
        } catch (DiscoveryError $error) {
            $this->console->error($error->getMessage(), $arguments->has('no-ansi'));
            return CommandResult::failure();
        }

        if ($manifest) {
            $document = TestManifest::document(
                $plan,
                $loaded->resolved->discovery->suites,
                $loaded->resolved->selection->shard,
                $workingDirectory,
            );
            $this->console->out(\json_encode(
                $document,
                \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_INVALID_UTF8_SUBSTITUTE | \JSON_THROW_ON_ERROR,
            ) . "\n");

            return CommandResult::success();
        }

        return !$standalone && $arguments->has('list-groups') ? $this->groups($plan) : $this->tests($plan);
    }

    private function tests(ExecutionPlan $plan): CommandResult
    {
        foreach ($plan->entries as $entry) {
            $this->console->out($entry->id . "\n");
        }
        $this->console->out(\sprintf("\n%d tests\n", \count($plan->entries)));
        return CommandResult::success();
    }

    private function groups(ExecutionPlan $plan): CommandResult
    {
        $counts = [];
        foreach ($plan->entries as $entry) {
            foreach ($entry->definition->groups as $group) {
                $counts[$group] = ($counts[$group] ?? 0) + 1;
            }
        }
        \ksort($counts, \SORT_STRING);
        foreach ($counts as $group => $count) {
            $this->console->out(\sprintf("%s (%d tests)\n", $group, $count));
        }
        $this->console->out(\sprintf("\n%d groups\n", \count($counts)));
        return CommandResult::success();
    }

    private function suites(ResolvedConfiguration $resolved): CommandResult
    {
        $suites = $resolved->discovery->suites;
        \usort($suites, static fn(SuiteConfiguration $a, SuiteConfiguration $b): int => \strcmp($a->name, $b->name));
        foreach ($suites as $suite) {
            $line = $suite->name . ': ' . \implode(', ', $suite->paths);
            if ($suite->tags !== []) {
                $line .= ' [tags: ' . \implode(', ', $suite->tags) . ']';
            }
            $this->console->out($line . "\n");
        }
        $this->console->out(\sprintf("\n%d suites\n", \count($suites)));
        return CommandResult::success();
    }

    private function warnWhenExcludePathsMatchNothing(SelectionDiscovery $discovery, bool $noAnsi): void
    {
        foreach ($discovery->unmatchedExcludePathWarnings() as $warning) {
            $this->console->err($this->console->stderrStyle($noAnsi)->warn($warning) . "\n");
        }
    }
}
