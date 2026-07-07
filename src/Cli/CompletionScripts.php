<?php

declare(strict_types=1);

namespace Greenlight\Cli;

/**
 * Renders the static shell completion scripts behind the completion command.
 *
 * The render() method returns the script for bash, zsh, or fish, and null
 * for any other shell name; SHELLS lists the accepted names for usage
 * messages. Scripts go to stdout and the user wires them into their shell,
 * so nothing here touches the filesystem.
 *
 * Flag candidates are generated from the OptionSpec list the argument
 * parser is built from: options that take a value complete as --flag= and
 * boolean options as --flag, so a new OptionSpec becomes completable in
 * every shell without touching this class. Command names and the value
 * lists for enum-like flags (--reporter, --workers, the completion shell
 * argument) are small explicit tables here.
 *
 * @internal
 */
final readonly class CompletionScripts
{
    public const array SHELLS = ['bash', 'zsh', 'fish'];

    private const array COMMANDS = [
        'run' => 'Discover and execute tests (default)',
        'list-tests' => 'List every discovered test id, one per line',
        'coverage:diff' => 'Compare two coverage JSON exports',
        'profile:report' => 'Render the run profile from a saved jsonl stream',
        'ide-helper' => 'Write the IDE autocomplete helper for extension matchers',
        'completion' => 'Print a shell completion script to stdout',
    ];

    private const array FLAG_VALUES = [
        'reporter' => ['tty', 'plain', 'junit', 'jsonl', 'github', 'teamcity'],
        'workers' => ['auto'],
    ];

    /**
     * @param list<OptionSpec> $specs
     */
    public function __construct(private array $specs) {}

    public function render(string $shell): ?string
    {
        return match ($shell) {
            'bash' => $this->bash(),
            'zsh' => $this->zsh(),
            'fish' => $this->fish(),
            default => null,
        };
    }

    private function bash(): string
    {
        $valueCases = '';

        foreach (self::FLAG_VALUES as $flag => $values) {
            $valueCases .= \sprintf(
                "        --%s=*)\n" .
                "            results=( \$(compgen -W \"%s\" -P \"--%s=\" -- \"\${logical#--%s=}\") )\n" .
                "            ;;\n",
                $flag,
                \implode(' ', $values),
                $flag,
                $flag,
            );
        }

        $template = <<<'BASH'
            # bash completion for greenlight. Load it into the current shell with:
            #   source <(greenlight completion bash)

            _greenlight_completions()
            {
                local cur=${COMP_WORDS[COMP_CWORD]}
                local i=$COMP_CWORD

                # COMP_WORDBREAKS normally contains ':' and '=', so bash splits
                # 'coverage:diff' and '--reporter=tty' across several COMP_WORDS.
                # Reassemble the logical word; the lead bash keeps on the line is
                # stripped from every candidate afterwards.
                local logical=$cur
                while (( i > 1 )) && [[ ${COMP_WORDS[i-1]} == [:=] || $logical == [:=]* ]]; do
                    (( i-- ))
                    logical=${COMP_WORDS[i]}$logical
                done
                local lead=${logical%"$cur"}

                local -a results=()

                case "$logical" in
            {{VALUE_CASES}}        -*)
                        results=( $(compgen -W "{{FLAGS}}" -- "$logical") )
                        ;;
                    *)
                        if [[ ${COMP_WORDS[1]-} == completion ]] && (( i == 2 )); then
                            results=( $(compgen -W "{{SHELLS}}" -- "$logical") )
                        elif (( i == 1 )); then
                            results=( $(compgen -W "{{COMMANDS}}" -- "$logical") )
                        fi
                        ;;
                esac

                COMPREPLY=( "${results[@]#"$lead"}" )

                if [[ ${#COMPREPLY[@]} -eq 1 && ${COMPREPLY[0]} == *= ]]; then
                    compopt -o nospace 2>/dev/null
                fi
            }

            complete -F _greenlight_completions greenlight

            BASH;

        return \str_replace(
            ['{{VALUE_CASES}}', '{{FLAGS}}', '{{SHELLS}}', '{{COMMANDS}}'],
            [
                $valueCases,
                \implode(' ', [...$this->valueFlagWords(), ...$this->booleanFlagWords()]),
                \implode(' ', self::SHELLS),
                \implode(' ', \array_keys(self::COMMANDS)),
            ],
            $template,
        );
    }

    private function zsh(): string
    {
        $valueBlocks = '';

        foreach (self::FLAG_VALUES as $flag => $values) {
            $valueBlocks .= \sprintf(
                "    if compset -P '--%s='; then\n" .
                "        compadd -- %s\n" .
                "        return\n" .
                "    fi\n\n",
                $flag,
                \implode(' ', $values),
            );
        }

        $commandLines = '';

        foreach (self::COMMANDS as $command => $description) {
            $commandLines .= \sprintf(
                "            '%s:%s'\n",
                \str_replace(':', '\:', $command),
                $description,
            );
        }

        $template = <<<'ZSH'
            #compdef greenlight
            # zsh completion for greenlight. Load it into the current shell with:
            #   source <(greenlight completion zsh)

            _greenlight()
            {
            {{VALUE_BLOCKS}}    if [[ ${words[CURRENT]} == -* ]]; then
                    compadd -S '' -- {{VALUE_FLAGS}}
                    compadd -- {{BOOLEAN_FLAGS}}
                    return
                fi

                if [[ ${words[2]-} == completion ]] && (( CURRENT == 3 )); then
                    compadd -- {{SHELLS}}
                    return
                fi

                if (( CURRENT == 2 )); then
                    local -a commands=(
            {{COMMAND_LINES}}        )
                    _describe -t commands 'greenlight command' commands
                fi
            }

            if [[ ${funcstack[1]-} == _greenlight ]]; then
                _greenlight "$@"
            else
                compdef _greenlight greenlight
            fi

            ZSH;

        return \str_replace(
            ['{{VALUE_BLOCKS}}', '{{VALUE_FLAGS}}', '{{BOOLEAN_FLAGS}}', '{{SHELLS}}', '{{COMMAND_LINES}}'],
            [
                $valueBlocks,
                \implode(' ', $this->valueFlagWords()),
                \implode(' ', $this->booleanFlagWords()),
                \implode(' ', self::SHELLS),
                $commandLines,
            ],
            $template,
        );
    }

    private function fish(): string
    {
        $lines = [
            '# fish completion for greenlight. Install it with:',
            '#   greenlight completion fish > ~/.config/fish/completions/greenlight.fish',
            '',
        ];

        foreach (self::COMMANDS as $command => $description) {
            $lines[] = \sprintf(
                "complete -c greenlight -f -n __fish_use_subcommand -a %s -d '%s'",
                $command,
                $description,
            );
        }

        $lines[] = \sprintf(
            "complete -c greenlight -f -n '__fish_seen_subcommand_from completion' -a '%s'",
            \implode(' ', self::SHELLS),
        );
        $lines[] = '';

        foreach ($this->specs as $spec) {
            $line = 'complete -c greenlight -l ' . $spec->name;

            if ($spec->short !== null) {
                $line .= ' -s ' . $spec->short;
            }

            $values = self::FLAG_VALUES[$spec->name] ?? null;

            if ($values !== null) {
                $line .= " -x -a '" . \implode(' ', $values) . "'";
            } elseif ($spec->value === OptionValue::Required) {
                $line .= ' -r';
            }

            $lines[] = $line;
        }

        return \implode("\n", $lines) . "\n";
    }

    /**
     * @return list<string>
     */
    private function valueFlagWords(): array
    {
        $words = [];

        foreach ($this->specs as $spec) {
            if ($spec->value !== OptionValue::None) {
                $words[] = '--' . $spec->name . '=';
            }
        }

        return $words;
    }

    /**
     * @return list<string>
     */
    private function booleanFlagWords(): array
    {
        $words = [];

        foreach ($this->specs as $spec) {
            if ($spec->value === OptionValue::None) {
                $words[] = '--' . $spec->name;
            }
        }

        return $words;
    }
}
