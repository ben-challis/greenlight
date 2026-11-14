<?php

declare(strict_types=1);

namespace Greenlight\Cli\Input;

/**
 * Gets flag candidates from OptionSpec. Explicit tables contain command names
 * and enum-like values.
 *
 * With a command option map, each script offers only the flags of the command
 * on the line. The run options apply when the line has no command word. An
 * unknown command, such as a plugin command, gets all flags.
 *
 * @internal
 */
final readonly class CompletionScripts
{
    private const string CASE_END = ';;';

    /**
     * @param list<OptionSpec> $specs
     * @param array<string, list<string>> $commandOptions Option names for each command.
     */
    public function __construct(private array $specs, private array $commandOptions = []) {}

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

        foreach (Definition::COMPLETION_VALUES as $flag => $values) {
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
            # bash completion for Greenlight. Load it into the current shell with:
            #   source <(greenlight completion bash)
            # Reporter completion suggests built-ins. Configured names remain valid.

            _greenlight_completions()
            {
                local cur=${COMP_WORDS[COMP_CWORD]}
                local i=$COMP_CWORD

                # COMP_WORDBREAKS normally contains ':' and '=', so bash splits
                # 'coverage:diff' and '--reporter=tty' across several COMP_WORDS.
                # Reassemble the logical word. Remove from each candidate the
                # leading part that bash keeps on the line.
                local logical=$cur
                while (( i > 1 )) && [[ ${COMP_WORDS[i-1]} == [:=] || $logical == [:=]* ]]; do
                    (( i-- ))
                    logical=${COMP_WORDS[i]}$logical
                done
                local lead=${logical%"$cur"}

                local -a results=()

                case "$logical" in
            {{VALUE_CASES}}        -*)
                        local command=run j word
                        for (( j = 1; j < i; j++ )); do
                            word=${COMP_WORDS[j]}
                            if [[ $word == = ]]; then
                                (( j++ ))
                                continue
                            fi
                            [[ $word == -* ]] && continue
                            while [[ ${COMP_WORDS[j+1]-} == : ]]; do
                                word+=":${COMP_WORDS[j+2]-}"
                                (( j += 2 ))
                            done
                            command=$word
                            break
                        done
                        case "$command" in
            {{FLAG_CASES}}                *) results=( $(compgen -W "{{FLAGS}}" -- "$logical") ) ;;
                        esac
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
            ['{{VALUE_CASES}}', '{{FLAG_CASES}}', '{{FLAGS}}', '{{SHELLS}}', '{{COMMANDS}}'],
            [
                $valueCases,
                $this->bashFlagCases(),
                \implode(' ', [...$this->valueFlagWords(), ...$this->booleanFlagWords()]),
                \implode(' ', Definition::COMPLETION_SHELLS),
                \implode(' ', \array_keys(Definition::COMMAND_DESCRIPTIONS)),
            ],
            $template,
        );
    }

    private function zsh(): string
    {
        $valueBlocks = '';

        foreach (Definition::COMPLETION_VALUES as $flag => $values) {
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

        foreach (Definition::COMMAND_DESCRIPTIONS as $command => $description) {
            $commandLines .= \sprintf(
                "            '%s:%s'\n",
                \str_replace(':', '\:', $command),
                $description,
            );
        }

        $template = <<<'ZSH'
            #compdef greenlight
            # zsh completion for Greenlight. Load it into the current shell with:
            #   source <(greenlight completion zsh)
            # Reporter completion suggests built-ins. Configured names remain valid.

            _greenlight()
            {
            {{VALUE_BLOCKS}}    if [[ ${words[CURRENT]} == -* ]]; then
                    local command=run word
                    for word in ${words[2,CURRENT-1]}; do
                        if [[ $word != -* ]]; then
                            command=$word
                            break
                        fi
                    done
                    case $command in
            {{FLAG_CASES}}            *)
                            compadd -S '' -- {{VALUE_FLAGS}}
                            compadd -- {{BOOLEAN_FLAGS}}
                            ;;
                    esac
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
            ['{{VALUE_BLOCKS}}', '{{FLAG_CASES}}', '{{VALUE_FLAGS}}', '{{BOOLEAN_FLAGS}}', '{{SHELLS}}', '{{COMMAND_LINES}}'],
            [
                $valueBlocks,
                $this->zshFlagCases(),
                \implode(' ', $this->valueFlagWords()),
                \implode(' ', $this->booleanFlagWords()),
                \implode(' ', Definition::COMPLETION_SHELLS),
                $commandLines,
            ],
            $template,
        );
    }

    private function fish(): string
    {
        $lines = [
            '# fish completion for Greenlight. Install it with:',
            '#   greenlight completion fish > ~/.config/fish/completions/greenlight.fish',
            '# Reporter completion suggests built-ins. Configured names remain valid.',
            '',
        ];

        foreach (Definition::COMMAND_DESCRIPTIONS as $command => $description) {
            $lines[] = \sprintf(
                "complete -c greenlight -f -n __fish_use_subcommand -a %s -d '%s'",
                $command,
                $description,
            );
        }

        $lines[] = \sprintf(
            "complete -c greenlight -f -n '__fish_seen_subcommand_from completion' -a '%s'",
            \implode(' ', Definition::COMPLETION_SHELLS),
        );
        $lines[] = '';

        foreach ($this->specs as $spec) {
            $line = 'complete -c greenlight' . $this->fishCondition($spec->name) . ' -l ' . $spec->name;

            if ($spec->short !== null) {
                $line .= ' -s ' . $spec->short;
            }

            $values = Definition::COMPLETION_VALUES[$spec->name] ?? null;

            if ($values !== null) {
                $line .= " -x -a '" . \implode(' ', $values) . "'";
            } elseif ($spec->value === OptionValue::Required) {
                $line .= ' -r';
            }

            $lines[] = $line;
        }

        return \implode("\n", $lines) . "\n";
    }

    private function bashFlagCases(): string
    {
        $cases = '';

        foreach ($this->commandOptions as $command => $names) {
            $cases .= \sprintf(
                <<<'BASH'
                                %s) results=( $(compgen -W "%s" -- "$logical") ) %s

                BASH,
                $this->casePattern($command),
                \implode(' ', [...$this->valueFlagWords($names), ...$this->booleanFlagWords($names)]),
                self::CASE_END,
            );
        }

        return $cases;
    }

    private function zshFlagCases(): string
    {
        $cases = '';

        foreach ($this->commandOptions as $command => $names) {
            $cases .= \sprintf(
                <<<'ZSH'
                                %s)
                                    compadd -S '' -- %s
                                    compadd -- %s
                                    %s

                ZSH,
                $this->casePattern($command),
                \implode(' ', $this->valueFlagWords($names)),
                \implode(' ', $this->booleanFlagWords($names)),
                self::CASE_END,
            );
        }

        return $cases;
    }

    /**
     * Returns a fish condition that is true for each command that accepts
     * the option. The run options also apply before a command word.
     */
    private function fishCondition(string $option): string
    {
        if ($this->commandOptions === []) {
            return '';
        }

        $commands = [];
        $all = true;

        foreach ($this->commandOptions as $command => $names) {
            if (\in_array($option, $names, true)) {
                $commands[] = $command;
            } else {
                $all = false;
            }
        }

        if ($all) {
            return '';
        }

        $condition = $commands === []
            ? 'false'
            : '__fish_seen_subcommand_from ' . \implode(' ', $commands);

        if (\in_array('run', $commands, true)) {
            $condition = '__fish_use_subcommand; or ' . $condition;
        }

        return " -n '" . $condition . "'";
    }

    private function casePattern(string $command): string
    {
        return \str_replace(':', '\\:', $command);
    }

    /**
     * @param list<string>|null $names
     *
     * @return list<string>
     */
    private function valueFlagWords(?array $names = null): array
    {
        $words = [];

        foreach ($this->specs as $spec) {
            if ($names !== null && !\in_array($spec->name, $names, true)) {
                continue;
            }

            if ($spec->value !== OptionValue::None) {
                $words[] = '--' . $spec->name . '=';
            }
        }

        return $words;
    }

    /**
     * @param list<string>|null $names
     *
     * @return list<string>
     */
    private function booleanFlagWords(?array $names = null): array
    {
        $words = [];

        foreach ($this->specs as $spec) {
            if ($names !== null && !\in_array($spec->name, $names, true)) {
                continue;
            }

            if ($spec->value === OptionValue::None) {
                $words[] = '--' . $spec->name;
            }
        }

        return $words;
    }
}
