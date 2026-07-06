<?php

declare(strict_types=1);

namespace Greenlight\Cli;

use Greenlight\Config\WorkerCount;

/**
 * The settings the command line can override, already validated and typed.
 * A null field (or an empty group list) means the flag was absent and the
 * config file value stands.
 *
 * @internal
 */
final readonly class CliOverrides
{
    /**
     * @param positive-int|null $stopAfterFailures
     * @param list<non-empty-string> $groups
     * @param int<0, max>|null $seed
     */
    public function __construct(
        public ?WorkerCount $workers = null,
        public ?int $stopAfterFailures = null,
        public array $groups = [],
        public ?int $seed = null,
    ) {}

    /**
     * @throws CliError
     */
    public static function fromArguments(ParsedArguments $arguments): self
    {
        $workers = null;

        if ($arguments->has('workers')) {
            $raw = $arguments->value('workers') ?? '';
            $workers = $raw === 'auto'
                ? WorkerCount::auto()
                : WorkerCount::exactly(self::positiveInt($raw, '--workers'));
        }

        $stopAfterFailures = null;

        if ($arguments->has('bail')) {
            $raw = $arguments->value('bail');
            $stopAfterFailures = $raw === null ? 1 : self::positiveInt($raw, '--bail');
        }

        $groups = [];

        foreach ($arguments->values('group') as $group) {
            if ($group === '') {
                throw new CliError('--group requires a non-empty group name.');
            }

            $groups[] = $group;
        }

        $seed = null;

        if ($arguments->has('seed')) {
            $raw = $arguments->value('seed') ?? '';

            if (\preg_match('/^\d+$/', $raw) !== 1) {
                throw new CliError(\sprintf('--seed must be a non-negative integer, got "%s".', $raw));
            }

            $parsed = (int) $raw;

            if ($parsed < 0) {
                throw new CliError(\sprintf('--seed must be a non-negative integer, got "%s".', $raw));
            }

            $seed = $parsed;
        }

        return new self($workers, $stopAfterFailures, $groups, $seed);
    }

    /**
     * @return positive-int
     */
    private static function positiveInt(string $raw, string $flag): int
    {
        $value = \preg_match('/^\d+$/', $raw) === 1 ? (int) $raw : 0;

        if ($value < 1) {
            throw new CliError(\sprintf('%s must be a positive integer, got "%s".', $flag, $raw));
        }

        return $value;
    }
}
