<?php

declare(strict_types=1);

namespace Greenlight\Cli\Configuration;

use Greenlight\Cli\Input\CliError;
use Greenlight\Cli\Input\ParsedArguments;
use Greenlight\Config\InvalidConfiguration;
use Greenlight\Config\WorkerCount;
use Greenlight\Internal\Text\DecimalInteger;
use Greenlight\Result\ResultPolicy;
use Greenlight\Test\ResourceName;
use Greenlight\Test\TestExclusions;
use Greenlight\Test\TestInclusions;
use Greenlight\Test\TestSelection;

/**
 * Contains validated command-line values grouped by their consumer.
 *
 * @internal
 */
final readonly class CliOverrides
{
    /** @param int<0, max>|null $seed */
    public function __construct(
        public ExecutionOverrides $execution = new ExecutionOverrides(),
        public TestSelection $selection = new TestSelection(),
        public ?int $seed = null,
        public RepeatConfiguration $repeat = new RepeatConfiguration(),
    ) {}

    /**
     * @throws CliError
     * @throws InvalidConfiguration
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
            execution: new ExecutionOverrides(
                workers: $workers,
                stopAfterFailures: $stopAfterFailures,
                policy: new ResultPolicy(
                    failOnDeprecation: $arguments->has('fail-on-deprecation'),
                    failOnNotice: $arguments->has('fail-on-notice'),
                    failOnRisky: $arguments->has('fail-on-risky'),
                ),
                artifactsDirectory: $artifactsDirectory,
                resourceLimits: $resourceLimits,
            ),
            selection: new TestSelection(
                include: new TestInclusions(groups: $groups, idPatterns: $filters, exactIds: $testIds),
                exclude: new TestExclusions($excludeGroups, $excludeClasses, $excludeMethods, $excludePaths),
                shard: $shard,
            ),
            seed: $seed,
            repeat: new RepeatConfiguration($repeat, $repeatUntilFailure),
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
     * @throws CliError
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
