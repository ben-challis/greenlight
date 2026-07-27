<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Cli;

use Greenlight\Attribute\Test;
use Greenlight\Cli\CompletionScripts;
use Greenlight\Cli\OptionSpec;
use Greenlight\Cli\OptionValue;
use Greenlight\Expect\Expect;

final class CompletionScriptsTest
{
    #[Test]
    public function rendersTheCommandNamesForEveryShell(): void
    {
        foreach (['bash', 'zsh', 'fish'] as $shell) {
            $script = (string) $this->scripts()->render($shell);

            foreach (['run', 'list-tests', 'coverage:diff', 'profile:report', 'ide-helper', 'completion'] as $command) {
                // The zsh _describe entries use an escape before the colon in a
                // command name.
                Expect::that($script)->toContain($shell === 'zsh' ? \str_replace(':', '\:', $command) : $command);
            }
        }
    }

    #[Test]
    public function rendersExactCommandDescriptionsWhenTheShellSupportsThem(): void
    {
        foreach (['zsh', 'fish'] as $shell) {
            $script = (string) $this->scripts()->render($shell);

            Expect::that($script)
                ->toContain('Find and run tests (default)')
                ->toContain('List each found test ID, one per line')
                ->toContain('Compare two coverage JSON exports')
                ->toContain('Create a run profile from a saved JSONL stream')
                ->toContain('Write the IDE autocomplete helper for extension matchers')
                ->toContain('Print a shell completion script to standard output');
        }
    }

    #[Test]
    public function generatesFlagCandidatesFromTheOptionSpecList(): void
    {
        foreach (['bash', 'zsh'] as $shell) {
            $script = (string) $this->scripts()->render($shell);

            Expect::that($script)
                ->toContain('--only-in-the-spec-table=')
                ->toContain('--watch');
        }

        $script = (string) $this->scripts()->render('fish');
        Expect::that($script)->because('generates flag candidates from the option spec list')
            ->toContain('-l only-in-the-spec-table -r')
            ->toContain('-l watch');
    }

    #[Test]
    public function offersReporterValuesAndCompletionShellArguments(): void
    {
        foreach (['bash', 'zsh', 'fish'] as $shell) {
            $script = (string) $this->scripts()->render($shell);

            foreach (['tty', 'plain', 'junit', 'jsonl', 'github', 'teamcity'] as $reporter) {
                Expect::that($script)->toContain($reporter);
            }

            Expect::that($script)->toContain('bash zsh fish');
        }
    }

    #[Test]
    public function returnsNullForAnUnknownShell(): void
    {
        Expect::that($this->scripts()->render('powershell'))->because('returns null for an unknown shell')->toBeNull();
    }

    private function scripts(): CompletionScripts
    {
        return new CompletionScripts([
            new OptionSpec('config', OptionValue::Required),
            new OptionSpec('workers', OptionValue::Required),
            new OptionSpec('reporter', OptionValue::Required, repeatable: true),
            new OptionSpec('only-in-the-spec-table', OptionValue::Required),
            new OptionSpec('watch'),
            new OptionSpec('help', short: 'h'),
        ]);
    }
}
