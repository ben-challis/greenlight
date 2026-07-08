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
        );
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
