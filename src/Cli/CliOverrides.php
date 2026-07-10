<?php

declare(strict_types=1);

namespace Greenlight\Cli;

use Greenlight\Config\WorkerCount;

/**
 * The settings the command line can override, already validated and typed.
 *
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
     * @param list<non-empty-string> $filters
     * @param array{int, int}|null $shard
     * @param list<non-empty-string> $excludeGroups
     * @param list<non-empty-string> $excludeClasses
     * @param list<non-empty-string> $excludeMethods
     * @param list<non-empty-string> $excludePaths
     * @param positive-int|null $repeat
     */
    public function __construct(
        public ?WorkerCount $workers = null,
        public ?int $stopAfterFailures = null,
        public array $groups = [],
        public ?int $seed = null,
        public array $filters = [],
        public ?array $shard = null,
        public bool $failOnDeprecation = false,
        public bool $failOnNotice = false,
        public bool $failOnRisky = false,
        public array $excludeGroups = [],
        public array $excludeClasses = [],
        public array $excludeMethods = [],
        public array $excludePaths = [],
        public ?int $repeat = null,
        public bool $repeatUntilFailure = false,
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
                throw CliError::emptyGroupName();
            }

            $groups[] = $group;
        }

        $filters = [];

        foreach ($arguments->values('filter') as $pattern) {
            if ($pattern === '') {
                throw CliError::emptyFilterPattern();
            }

            $filters[] = $pattern;
        }

        $shard = null;

        if ($arguments->has('shard')) {
            $raw = $arguments->value('shard') ?? '';

            if (\preg_match('/^(\d+)\/(\d+)$/', $raw, $matches) !== 1) {
                throw CliError::malformedShard($raw);
            }

            $index = (int) $matches[1];
            $count = (int) $matches[2];

            if ($count < 1 || $index < 1 || $index > $count) {
                throw CliError::shardOutOfRange($raw);
            }

            $shard = [$index, $count];
        }

        $excludeGroups = self::nonEmptyValues($arguments, 'exclude-group');
        $excludeClasses = self::nonEmptyValues($arguments, 'exclude-class');
        $excludeMethods = self::nonEmptyValues($arguments, 'exclude-method');
        $excludePaths = self::nonEmptyValues($arguments, 'exclude-path');

        $repeat = null;

        if ($arguments->has('repeat')) {
            $repeat = self::positiveInt($arguments->value('repeat') ?? '', '--repeat');
        }

        $repeatUntilFailure = $arguments->has('repeat-until-failure');

        $seed = null;

        if ($arguments->has('seed')) {
            $raw = $arguments->value('seed') ?? '';

            if (\preg_match('/^\d+$/', $raw) !== 1) {
                throw CliError::invalidSeed($raw);
            }

            $parsed = (int) $raw;

            if ($parsed < 0) {
                throw CliError::invalidSeed($raw);
            }

            $seed = $parsed;
        }

        return new self(
            $workers,
            $stopAfterFailures,
            $groups,
            $seed,
            $filters,
            $shard,
            $arguments->has('fail-on-deprecation'),
            $arguments->has('fail-on-notice'),
            $arguments->has('fail-on-risky'),
            $excludeGroups,
            $excludeClasses,
            $excludeMethods,
            $excludePaths,
            $repeat,
            $repeatUntilFailure,
        );
    }

    /**
     * @return list<non-empty-string>
     *
     * @throws CliError
     */
    private static function nonEmptyValues(ParsedArguments $arguments, string $name): array
    {
        $values = [];

        foreach ($arguments->values($name) as $value) {
            if ($value === '') {
                throw CliError::optionRequiresValue($name);
            }

            $values[] = $value;
        }

        return $values;
    }

    /**
     * @return positive-int
     */
    private static function positiveInt(string $raw, string $flag): int
    {
        $value = \preg_match('/^\d+$/', $raw) === 1 ? (int) $raw : 0;

        if ($value < 1) {
            throw CliError::notAPositiveInteger($flag, $raw);
        }

        return $value;
    }
}
