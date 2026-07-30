<?php

declare(strict_types=1);

namespace Greenlight\Cli;

use Greenlight\Config\WorkerCount;
use Greenlight\Core\DecimalInteger;
use Greenlight\Core\Test\ResourceName;

/**
 * Contains validated typed settings that the command line can override.
 *
 * A null field or empty group list means that the flag was absent. The value
 * from the configuration file remains in effect.
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
     * @param list<non-empty-string> $testIds
     * @param array{int, int}|null $shard
     * @param list<non-empty-string> $excludeGroups
     * @param list<non-empty-string> $excludeClasses
     * @param list<non-empty-string> $excludeMethods
     * @param list<non-empty-string> $excludePaths
     * @param positive-int|null $repeat
     * @param array<non-empty-string, positive-int> $resourceLimits
     */
    public function __construct(
        public ?WorkerCount $workers = null,
        public ?int $stopAfterFailures = null,
        public array $groups = [],
        public ?int $seed = null,
        public array $filters = [],
        public array $testIds = [],
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
        public ?string $artifactsDirectory = null,
        public array $resourceLimits = [],
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

        $testIds = self::nonEmptyValues($arguments, 'test-id');
        $shard = null;

        if ($arguments->has('shard')) {
            $raw = $arguments->value('shard') ?? '';

            if (\preg_match('/^(\d+)\/(\d+)\z/', $raw, $matches) !== 1) {
                throw CliError::malformedShard($raw);
            }

            $index = DecimalInteger::parse($matches[1]);
            $count = DecimalInteger::parse($matches[2]);

            if ($index === null
                || $count === null
                || $count < 1
                || $index < 1
                || $index > $count
            ) {
                throw CliError::shardOutOfRange($raw, $count ?? 0);
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
        $artifactsDirectory = null;

        if ($arguments->has('artifacts-dir')) {
            $artifactsDirectory = $arguments->value('artifacts-dir') ?? '';

            if ($artifactsDirectory === '') {
                throw CliError::optionRequiresValue('artifacts-dir');
            }
        }

        $resourceLimits = [];

        foreach ($arguments->values('resource-limit') as $raw) {
            $parts = \explode('=', $raw);

            if (\count($parts) !== 2) {
                throw CliError::malformedResourceLimit($raw);
            }

            [$name, $rawLimit] = $parts;

            try {
                ResourceName::assertValid($name);
            } catch (\InvalidArgumentException) {
                throw CliError::malformedResourceLimit($raw);
            }

            $limit = self::positiveInt($rawLimit, '--resource-limit');

            if (\array_key_exists($name, $resourceLimits)) {
                throw CliError::duplicateResourceLimit($name);
            }

            $resourceLimits[$name] = $limit;
        }

        $seed = null;

        if ($arguments->has('seed')) {
            $raw = $arguments->value('seed') ?? '';

            $parsed = DecimalInteger::parse($raw);

            if ($parsed === null) {
                throw CliError::invalidSeed($raw);
            }

            $seed = $parsed;
        }

        return new self(
            workers: $workers,
            stopAfterFailures: $stopAfterFailures,
            groups: $groups,
            seed: $seed,
            filters: $filters,
            testIds: $testIds,
            shard: $shard,
            failOnDeprecation: $arguments->has('fail-on-deprecation'),
            failOnNotice: $arguments->has('fail-on-notice'),
            failOnRisky: $arguments->has('fail-on-risky'),
            excludeGroups: $excludeGroups,
            excludeClasses: $excludeClasses,
            excludeMethods: $excludeMethods,
            excludePaths: $excludePaths,
            repeat: $repeat,
            repeatUntilFailure: $repeatUntilFailure,
            artifactsDirectory: $artifactsDirectory,
            resourceLimits: $resourceLimits,
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
        $value = DecimalInteger::parse($raw);

        if ($value === null || $value < 1) {
            throw CliError::notAPositiveInteger($flag, $raw);
        }

        return $value;
    }
}
