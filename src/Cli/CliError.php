<?php

declare(strict_types=1);

namespace Greenlight\Cli;

/**
 * A user-facing command-line usage error.
 *
 * The application prints the message and exits with the usage error code.
 *
 * @internal
 */
final class CliError extends \RuntimeException
{
    private function __construct(string $message)
    {
        parent::__construct($message);
    }

    public static function unknownOption(string $option): self
    {
        return new self(\sprintf('Unknown option "%s". Run greenlight --help for the available options.', $option));
    }

    public static function bareDoubleDash(): self
    {
        return new self('Unexpected bare "--".');
    }

    public static function optionTakesNoValue(string $name): self
    {
        return new self(\sprintf('Option --%s does not take a value.', $name));
    }

    public static function optionRequiresValue(string $name): self
    {
        return new self(\sprintf('Option --%s requires a value, use --%s=<value>.', $name, $name));
    }

    public static function shortOptionRequiresValue(string $short, string $name): self
    {
        return new self(\sprintf('Option -%s requires a value, use --%s=<value>.', $short, $name));
    }

    public static function unexpectedArgument(string $argument): self
    {
        return new self(\sprintf('Unexpected argument "%s".', $argument));
    }

    public static function duplicateOption(string $name): self
    {
        return new self(\sprintf('Option --%s cannot be given more than once.', $name));
    }

    public static function emptyGroupName(): self
    {
        return new self('--group requires a non-empty group name.');
    }

    public static function emptyFilterPattern(): self
    {
        return new self('--filter requires a non-empty pattern.');
    }

    public static function malformedShard(string $raw): self
    {
        return new self(\sprintf('--shard must look like <n>/<m>, for example 1/4, got "%s".', $raw));
    }

    public static function shardOutOfRange(string $raw): self
    {
        return new self(\sprintf('--shard needs 1 <= n <= m, got "%s".', $raw));
    }

    public static function invalidSeed(string $raw): self
    {
        return new self(\sprintf('--seed must be a non-negative integer, got "%s".', $raw));
    }

    public static function notAPositiveInteger(string $flag, string $raw): self
    {
        return new self(\sprintf('%s must be a positive integer, got "%s".', $flag, $raw));
    }

    public static function unknownReporter(string $name): self
    {
        return new self(\sprintf(
            'Unknown reporter "%s". Available: tty, plain, junit, jsonl, github, teamcity.',
            $name,
        ));
    }
}
