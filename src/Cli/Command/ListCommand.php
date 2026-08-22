<?php

declare(strict_types=1);

namespace Greenlight\Cli\Command;

use Greenlight\Cli\Configuration\ConfigurationLoader;
use Greenlight\Cli\Discovery\SelectionDiscovery;
use Greenlight\Cli\Input\CliError;
use Greenlight\Cli\Input\ParsedArguments;
use Greenlight\Cli\Output\Console;
use Greenlight\Config\ConfigFileError;
use Greenlight\Config\InvalidConfiguration;
use Greenlight\Config\ResolvedConfiguration;
use Greenlight\Config\SuiteConfiguration;
use Greenlight\Discovery\DiscoveryError;
use Greenlight\Discovery\ExecutionPlan;

/**
 * Discovers and formats test, group, and suite listings.
 *
 * @internal
 */
final readonly class ListCommand
{
    public function __construct(private Console $console) {}

    public function run(ParsedArguments $arguments, string $workingDirectory): int
    {
        try {
            $loaded = new ConfigurationLoader()->load($arguments, $workingDirectory);
        } catch (CliError $error) {
            $this->console->error($error->getMessage(), $arguments->has('no-ansi'));
            return 64;
        } catch (ConfigFileError|InvalidConfiguration $error) {
            $this->console->error($error->getMessage(), $arguments->has('no-ansi'));
            return 1;
        }
        $standalone = $arguments->command === 'list-tests';
        if (!$standalone && $arguments->has('list-suites')) {
            return $this->suites($loaded->resolved);
        }
        $discovery = new SelectionDiscovery($loaded, $workingDirectory);
        $this->warnWhenExcludePathsMatchNothing($discovery, $arguments->has('no-ansi'));
        try {
            $plan = $discovery->plan();
        } catch (DiscoveryError $error) {
            $this->console->error($error->getMessage(), $arguments->has('no-ansi'));
            return 1;
        }
        return !$standalone && $arguments->has('list-groups') ? $this->groups($plan) : $this->tests($plan);
    }

    private function tests(ExecutionPlan $plan): int
    {
        foreach ($plan->entries as $entry) {
            $this->console->out($entry->id . "\n");
        }
        $this->console->out(\sprintf("\n%d tests\n", \count($plan->entries)));
        return 0;
    }

    private function groups(ExecutionPlan $plan): int
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
        return 0;
    }

    private function suites(ResolvedConfiguration $resolved): int
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
        return 0;
    }

    private function warnWhenExcludePathsMatchNothing(SelectionDiscovery $discovery, bool $noAnsi): void
    {
        foreach ($discovery->unmatchedExcludePathWarnings() as $warning) {
            $this->console->err($this->console->stderrStyle($noAnsi)->warn($warning) . "\n");
        }
    }
}
