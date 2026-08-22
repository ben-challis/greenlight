<?php

declare(strict_types=1);

namespace Greenlight\Cli\Input;

/**
 * A small Greenlight argument parser.
 *
 * Long options use --name or --name=value. Short aliases use one letter (-h)
 * and do not take values. The first word without a prefix is the command.
 *
 * @internal
 */
final class ArgumentParser
{
    /**
     * @var array<string, OptionSpec>
     */
    private array $byName = [];

    /**
     * @var array<string, OptionSpec>
     */
    private array $byShort = [];

    /**
     * @param list<OptionSpec> $specs
     *
     * @throws \InvalidArgumentException when option names or aliases conflict
     */
    public function __construct(array $specs)
    {
        foreach ($specs as $spec) {
            if (isset($this->byName[$spec->name])) {
                throw new \InvalidArgumentException(\sprintf(
                    'Option "--%s" is defined more than once.',
                    $spec->name,
                ));
            }

            $this->byName[$spec->name] = $spec;

            if ($spec->short !== null) {
                if (isset($this->byShort[$spec->short])) {
                    throw new \InvalidArgumentException(\sprintf(
                        'Short option "-%s" is assigned to more than one option.',
                        $spec->short,
                    ));
                }

                $this->byShort[$spec->short] = $spec;
            }
        }
    }

    /**
     * @param list<string> $argv
     *
     * @throws CliError
     */
    public function parse(array $argv): ParsedArguments
    {
        $command = null;

        /** @var array<string, list<string|null>> $options */
        $options = [];

        foreach ($argv as $argument) {
            if (\str_starts_with($argument, '--')) {
                $body = \substr($argument, 2);

                if ($body === '') {
                    throw CliError::bareDoubleDash();
                }

                $separator = \strpos($body, '=');
                if ($separator === false) {
                    $name = $body;
                    $value = null;
                } else {
                    $name = \substr($body, 0, $separator);
                    $value = \substr($body, $separator + 1);
                }

                $spec = $this->byName[$name] ?? throw CliError::unknownOption('--' . $name);

                if ($value !== null && $spec->value === OptionValue::None) {
                    throw CliError::optionTakesNoValue($name);
                }

                if ($value === null && $spec->value === OptionValue::Required) {
                    throw CliError::optionRequiresValue($name);
                }

                $this->record($options, $spec, $value);
            } elseif (\str_starts_with($argument, '-') && $argument !== '-') {
                $short = \substr($argument, 1);
                $spec = $this->byShort[$short] ?? throw CliError::unknownOption($argument);

                if ($spec->value === OptionValue::Required) {
                    throw CliError::shortOptionRequiresValue($short, $spec->name);
                }

                $this->record($options, $spec, null);
            } elseif ($command === null) {
                $command = $argument;
            } else {
                throw CliError::unexpectedArgument($argument);
            }
        }

        return new ParsedArguments($command, $options);
    }

    /**
     * @param array<string, list<string|null>> $options
     * @throws CliError
     */
    private function record(array &$options, OptionSpec $spec, ?string $value): void
    {
        if (!$spec->repeatable && isset($options[$spec->name])) {
            throw CliError::duplicateOption($spec->name);
        }

        $options[$spec->name][] = $value;
    }
}
