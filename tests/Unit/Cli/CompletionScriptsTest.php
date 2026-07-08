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
                // The zsh _describe entries escape the colon in a command name.
                Expect::that($script)->toContain($shell === 'zsh' ? \str_replace(':', '\:', $command) : $command);
            }
        }
    }

    #[Test]
    public function generatesFlagCandidatesFromTheOptionSpecList(): void
    {
        foreach (['bash', 'zsh'] as $shell) {
            Expect::that((string) $this->scripts()->render($shell))
                ->toContain('--only-in-the-spec-table=')
                ->and((string) $this->scripts()->render($shell))->toContain('--watch');
        }

        Expect::that((string) $this->scripts()->render('fish'))
            ->toContain('-l only-in-the-spec-table -r')
            ->and((string) $this->scripts()->render('fish'))->toContain('-l watch');
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
        Expect::that($this->scripts()->render('powershell'))->toBeNull();
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
