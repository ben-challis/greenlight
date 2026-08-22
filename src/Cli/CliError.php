<?php

declare(strict_types=1);

namespace Greenlight\Cli;

/** @internal */
final class CliError extends \RuntimeException
{
    private function __construct(string $message)
    {
        parent::__construct($message);
    }

    public static function unknownOption(string $option): self
    {
        return new self(\sprintf('Unknown option "%s". Use greenlight --help to list options.', $option));
    }

    public static function bareDoubleDash(): self
    {
        return new self('"--" requires an option name.');
    }

    public static function optionTakesNoValue(string $name): self
    {
        return new self(\sprintf('Option --%s does not take a value.', $name));
    }

    public static function optionRequiresValue(string $name): self
    {
        return new self(\sprintf('Option --%s requires a value. Use --%s=<value>.', $name, $name));
    }

    public static function shortOptionRequiresValue(string $short, string $name): self
    {
        return new self(\sprintf('Option -%s requires a value. Use --%s=<value>.', $short, $name));
    }

    public static function unexpectedArgument(string $argument): self
    {
        return new self(\sprintf('Unexpected argument "%s".', $argument));
    }

    public static function duplicateOption(string $name): self
    {
        return new self(\sprintf('Specify option --%s only once.', $name));
    }

    public static function emptyGroupName(): self
    {
        return new self('--group requires a group name.');
    }

    public static function emptyFilterPattern(): self
    {
        return new self('--filter requires a pattern.');
    }

    public static function malformedShard(string $raw): self
    {
        return new self(\sprintf('--shard requires <n>/<m>, such as 1/4. Received "%s".', $raw));
    }

    public static function shardOutOfRange(string $raw, int $count): self
    {
        $message = \sprintf('--shard requires 1 <= n <= m. Received "%s".', $raw);

        if ($count >= 1) {
            $message .= \sprintf(
                ' Valid n values for %d %s are 1 through %d.',
                $count,
                $count === 1 ? 'shard' : 'shards',
                $count,
            );
        }

        return new self($message);
    }

    public static function invalidSeed(string $raw): self
    {
        return new self(\sprintf('--seed requires a nonnegative integer. Received "%s".', $raw));
    }

    public static function notAPositiveInteger(string $flag, string $raw): self
    {
        return new self(\sprintf('%s requires a positive integer. Received "%s".', $flag, $raw));
    }

    public static function malformedResourceLimit(string $raw): self
    {
        return new self(\sprintf(
            '--resource-limit requires <name>=<limit>, such as postgres=2. Received "%s".',
            $raw,
        ));
    }

    public static function duplicateResourceLimit(string $name): self
    {
        return new self(\sprintf('Set resource limit "%s" only once.', $name));
    }

    /** @param list<non-empty-string> $available */
    public static function unknownReporter(string $name, array $available): self
    {
        return new self(\sprintf(
            'Unknown reporter "%s". Select one of: %s.',
            $name,
            \implode(', ', $available),
        ));
    }

    /** @param non-empty-list<non-empty-string> $outputs */
    public static function repeatWithSingleRunOutput(array $outputs): self
    {
        return new self(\sprintf(
            'Do not use --repeat or --repeat-until-failure with %s. Run Greenlight separately for each required report.',
            \implode(' or ', $outputs),
        ));
    }

    public static function malformedReporterSelection(string $value): self
    {
        return new self(\sprintf(
            '--reporter requires <name> or <name>=<file>. Received "%s".',
            $value,
        ));
    }

    public static function duplicateReporterOutput(string $path): self
    {
        return new self(\sprintf('Write reporter output to file "%s" only once.', $path));
    }
}
