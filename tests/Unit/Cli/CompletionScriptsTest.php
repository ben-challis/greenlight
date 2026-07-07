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
        $expect = new Expect();

        foreach (['bash', 'zsh', 'fish'] as $shell) {
            $script = (string) $this->scripts()->render($shell);

            foreach (['run', 'list-tests', 'coverage:diff', 'profile:report', 'ide-helper', 'completion'] as $command) {
                // The zsh _describe entries escape the colon in a command name.
                $expect->that($script)->toContain($shell === 'zsh' ? \str_replace(':', '\:', $command) : $command);
            }
        }
    }

    #[Test]
    public function generatesFlagCandidatesFromTheOptionSpecList(): void
    {
        $expect = new Expect();

        foreach (['bash', 'zsh'] as $shell) {
            $expect->that((string) $this->scripts()->render($shell))
                ->toContain('--only-in-the-spec-table=')
                ->and((string) $this->scripts()->render($shell))->toContain('--watch');
        }

        $expect->that((string) $this->scripts()->render('fish'))
            ->toContain('-l only-in-the-spec-table -r')
            ->and((string) $this->scripts()->render('fish'))->toContain('-l watch');
    }

    #[Test]
    public function offersReporterValuesAndCompletionShellArguments(): void
    {
        $expect = new Expect();

        foreach (['bash', 'zsh', 'fish'] as $shell) {
            $script = (string) $this->scripts()->render($shell);

            foreach (['tty', 'plain', 'junit', 'jsonl', 'github', 'teamcity'] as $reporter) {
                $expect->that($script)->toContain($reporter);
            }

            $expect->that($script)->toContain('bash zsh fish');
        }
    }

    #[Test]
    public function returnsNullForAnUnknownShell(): void
    {
        new Expect()->that($this->scripts()->render('powershell'))->toBeNull();
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
