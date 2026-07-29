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
    public function offersTheAutomaticWorkerCountForEveryShell(): void
    {
        $scripts = $this->scripts();

        Expect::that($scripts->render('bash'))
            ->because('Bash MUST complete the automatic worker count for the workers flag')
            ->toContain('--workers=*)')
            ->and($scripts->render('bash'))
            ->toContain('compgen -W "auto" -P "--workers="');

        Expect::that($scripts->render('zsh'))
            ->because('Zsh MUST complete the automatic worker count for the workers flag')
            ->toContain("compset -P '--workers='")
            ->and($scripts->render('zsh'))
            ->toContain('compadd -- auto');

        Expect::that($scripts->render('fish'))
            ->because('Fish MUST complete the automatic worker count for the workers flag')
            ->toContain("complete -c greenlight -l workers -x -a 'auto'");
    }

    #[Test]
    public function optionalValuesDoNotBecomeRequiredInFish(): void
    {
        $scripts = new CompletionScripts([
            new OptionSpec('bail', OptionValue::Optional),
        ]);

        foreach (['bash', 'zsh'] as $shell) {
            Expect::that($scripts->render($shell))
                ->because('shells with equals-form completion MUST offer the optional value')
                ->toContain('--bail=');
        }

        Expect::that($scripts->render('fish'))
            ->because('fish MUST register an optional-value flag without requiring its argument')
            ->toContain("complete -c greenlight -l bail\n")
            ->not()
            ->toContain('complete -c greenlight -l bail -r');
    }

    #[Test]
    public function fishPreservesShortAliasesFromTheOptionSpecifications(): void
    {
        Expect::that($this->scripts()->render('fish'))
            ->because('fish completion MUST include each configured short option alias')
            ->toContain('complete -c greenlight -l help -s h');
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
