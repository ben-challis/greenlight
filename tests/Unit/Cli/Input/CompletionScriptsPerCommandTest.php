<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Cli\Input;

use Greenlight\Attribute\Test;
use Greenlight\Cli\Input\CompletionScripts;
use Greenlight\Cli\Input\Definition;
use Greenlight\Cli\Input\OptionSpec;
use Greenlight\Cli\Input\OptionValue;

use function Greenlight\expect;

final class CompletionScriptsPerCommandTest
{
    #[Test]
    public function bashOffersOnlyTheFlagsOfTheCommandOnTheLine(): void
    {
        $script = (string) $this->scripts()->render('bash');

        expect($script)
            ->toContain('run) results=( $(compgen -W "--workers= --help" -- "$logical") ) ;;')
            ->toContain('coverage\:diff) results=( $(compgen -W "--baseline= --help" -- "$logical") ) ;;')
            ->because('an unknown command, such as a plugin command, gets all flags')
            ->toContain('*) results=( $(compgen -W "--workers= --baseline= --help" -- "$logical") ) ;;');
    }

    #[Test]
    public function zshOffersOnlyTheFlagsOfTheCommandOnTheLine(): void
    {
        expect((string) $this->scripts()->render('zsh'))
            ->toContain("coverage\\:diff)\n                    compadd -S '' -- --baseline=\n                    compadd -- --help\n");
    }

    #[Test]
    public function fishLimitsEachFlagToTheCommandsThatAcceptIt(): void
    {
        expect((string) $this->scripts()->render('fish'))
            ->because('the run options also apply before a command word')
            ->toContain("complete -c greenlight -n '__fish_use_subcommand; or __fish_seen_subcommand_from run' -l workers -x -a 'auto'")
            ->toContain("complete -c greenlight -n '__fish_seen_subcommand_from coverage:diff' -l baseline -r")
            ->because('a global flag applies to every command')
            ->toContain("complete -c greenlight -l help -s h\n");
    }

    #[Test]
    public function theBundledDefinitionMapsEveryCommand(): void
    {
        $options = new Definition()->commandOptions();

        expect(\array_keys($options))->toBe(new Definition()->commands());
        expect($options['completion'])
            ->because('the completion command takes only the global options')
            ->toBe(Definition::GLOBAL_OPTIONS);
    }

    private function scripts(): CompletionScripts
    {
        return new CompletionScripts(
            [
                new OptionSpec('workers', OptionValue::Required),
                new OptionSpec('baseline', OptionValue::Required),
                new OptionSpec('help', short: 'h'),
            ],
            [
                'run' => ['workers', 'help'],
                'coverage:diff' => ['baseline', 'help'],
            ],
        );
    }
}
