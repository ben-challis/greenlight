<?php

declare(strict_types=1);

namespace Greenlight\Cli;

/**
 * A minimal owned argument parser.
 *
 * Long options use --name or --name=value; short aliases are single letters
 * (-h) and never take values. The first bare word is the command.
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
     */
    public function __construct(array $specs)
    {
        foreach ($specs as $spec) {
            $this->byName[$spec->name] = $spec;

            if ($spec->short !== null) {
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
                $name = $separator === false ? $body : \substr($body, 0, $separator);
                $value = $separator === false ? null : \substr($body, $separator + 1);

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
     */
    private function record(array &$options, OptionSpec $spec, ?string $value): void
    {
        if (!$spec->repeatable && isset($options[$spec->name])) {
            throw CliError::duplicateOption($spec->name);
        }

        $options[$spec->name][] = $value;
    }
}
