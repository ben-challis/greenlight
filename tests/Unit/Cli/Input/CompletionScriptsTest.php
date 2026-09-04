<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Cli\Input;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Cli\Input\CompletionScripts;
use Greenlight\Cli\Input\OptionSpec;
use Greenlight\Cli\Input\OptionValue;
use Greenlight\Expect\Expect;

final class CompletionScriptsTest
{
    #[Test]
    #[DataSet('shells')]
    public function rendersTheCommandNamesForEveryShell(string $shell): void
    {
        $script = (string) $this->scripts()->render($shell);

        foreach (['run', 'list-tests', 'coverage:merge', 'coverage:diff', 'profile:report', 'artifacts:prune', 'ide-helper', 'completion'] as $command) {
            // The zsh _describe entries use an escape before the colon in a
            // command name.
            Expect::that($script)->toContain($shell === 'zsh' ? \str_replace(':', '\:', $command) : $command);
        }
    }

    #[Test]
    #[DataSet('shellsWithDescriptions')]
    public function rendersExactCommandDescriptionsWhenTheShellSupportsThem(string $shell): void
    {
        $script = (string) $this->scripts()->render($shell);

        Expect::that($script)
            ->toContain('Find and run tests (default)')
            ->toContain('List selected test IDs and the total test count')
            ->toContain('Merge coverage JSON exports')
            ->toContain('Compare two coverage JSON exports')
            ->toContain('Create a run profile from a saved JSONL stream')
            ->toContain('Apply configured artifact retention')
            ->toContain('Write the IDE autocomplete helper for extension matchers')
            ->toContain('Print a shell completion script to standard output');
    }

    #[Test]
    #[DataSet('shells')]
    public function generatesFlagCandidatesFromTheOptionSpecList(string $shell): void
    {
        $script = (string) $this->scripts()->render($shell);

        if ($shell === 'fish') {
            Expect::that($script)->because('generates flag candidates from the option spec list')
                ->toContain('-l only-in-the-spec-table -r')
                ->toContain('-l watch');

            return;
        }

        Expect::that($script)
            ->toContain('--only-in-the-spec-table=')
            ->toContain('--watch');
    }

    #[Test]
    #[DataSet('shells')]
    public function offersReporterValuesAndCompletionShellArguments(string $shell): void
    {
        $script = (string) $this->scripts()->render($shell);

        foreach (['tty', 'plain', 'junit', 'jsonl', 'github', 'teamcity'] as $reporter) {
            Expect::that($script)->toContain($reporter);
        }

        Expect::that($script)
            ->because('completion MUST explain that custom reporter names remain valid')
            ->toContain('Configured names remain valid')
            ->toContain('bash zsh fish');
    }

    #[Test]
    public function offersTheAutomaticWorkerCountForEveryShell(): void
    {
        $scripts = $this->scripts();

        Expect::that($scripts->render('bash'))
            ->because('Bash MUST complete the automatic worker count for the workers flag')
            ->toContain('--workers=*)');
        Expect::that($scripts->render('bash'))
            ->toContain('compgen -W "auto" -P "--workers="');

        Expect::that($scripts->render('zsh'))
            ->because('Zsh MUST complete the automatic worker count for the workers flag')
            ->toContain("compset -P '--workers='");
        Expect::that($scripts->render('zsh'))
            ->toContain('compadd -- auto');

        Expect::that($scripts->render('fish'))
            ->because('Fish MUST complete the automatic worker count for the workers flag')
            ->toContain("complete -c greenlight -l workers -x -a 'auto'");
    }

    #[Test]
    #[DataSet('shells')]
    public function optionalValuesDoNotBecomeRequiredInFish(string $shell): void
    {
        $scripts = new CompletionScripts([
            new OptionSpec('bail', OptionValue::Optional),
        ]);

        if ($shell === 'fish') {
            Expect::that($scripts->render($shell))
                ->because('fish MUST register an optional-value flag without requiring its argument')
                ->toContain("complete -c greenlight -l bail\n")
                ->not()
                ->toContain('complete -c greenlight -l bail -r');

            return;
        }

        Expect::that($scripts->render($shell))
            ->because('shells with equals-form completion MUST offer the optional value')
            ->toContain('--bail=');
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

    /**
     * @return iterable<string, array{string}>
     */
    public static function shells(): iterable
    {
        yield 'bash' => ['bash'];
        yield 'zsh' => ['zsh'];
        yield 'fish' => ['fish'];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function shellsWithDescriptions(): iterable
    {
        yield 'zsh' => ['zsh'];
        yield 'fish' => ['fish'];
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
