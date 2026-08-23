<?php

declare(strict_types=1);

namespace Greenlight\Coverage\Attribution;

use Greenlight\Internal\Php\ErrorTrap;

/**
 * Reads the version 1 attribution stream for specified source lines.
 *
 * The reader keeps only the test table and records for the changed lines.
 *
 * @internal
 */
final class TestCoverageMap
{
    private const int VERSION = 1;

    /** @codeCoverageIgnore */
    private function __construct() {}

    /**
     * @param array<non-empty-string, non-empty-list<positive-int>> $changedLines
     * @return list<non-empty-string>
     * @throws TestCoverageMapError
     */
    public static function impactedTests(string $artifact, string $root, string $runId, array $changedLines): array
    {
        $stream = ErrorTrap::run(static fn() => \fopen($artifact, 'rb'), $warning);

        if (!\is_resource($stream)) {
            throw new TestCoverageMapError(\sprintf('The per-test coverage map "%s" is not readable.', $artifact));
        }

        /** @var array<non-empty-string, array<positive-int, true>> $wanted */
        $wanted = [];
        foreach ($changedLines as $file => $lines) {
            foreach ($lines as $line) {
                $wanted[$file][$line] = true;
            }
        }

        /** @var array<non-negative-int, non-empty-string> $tests */
        $tests = [];
        /** @var array<non-empty-string, array<positive-int, bool>> $source */
        $source = [];
        /** @var array<non-empty-string, array<positive-int, true>> $attributed */
        $attributed = [];
        /** @var array<non-empty-string, array<positive-int, true>> $unattributed */
        $unattributed = [];
        /** @var array<non-empty-string, true> $impacted */
        $impacted = [];
        $metadata = false;

        try {
            while (($line = \fgets($stream)) !== false) {
                $record = self::decode($line);

                if (($record['v'] ?? null) !== self::VERSION || !\is_string($record['type'] ?? null)) {
                    throw new TestCoverageMapError('The per-test coverage map contains an unsupported record.');
                }

                $type = $record['type'];

                if (!$metadata) {
                    self::validateMetadata($record, $root, $runId);
                    $metadata = true;

                    continue;
                }

                if ($type === 'meta') {
                    throw new TestCoverageMapError('The per-test coverage map contains more than one metadata record.');
                }

                if ($type === 'test') {
                    $ordinal = self::ordinal($record, 'test');
                    $id = self::requiredString($record, 'renderedId');

                    if (isset($tests[$ordinal])) {
                        throw new TestCoverageMapError('The per-test coverage map contains a duplicate test ordinal.');
                    }

                    $tests[$ordinal] = $id;

                    continue;
                }

                if (!\in_array($type, ['coverage', 'source', 'unattributed'], true)) {
                    continue;
                }

                $file = self::requiredString($record, 'file');
                $wantedLines = $wanted[$file] ?? null;

                if ($wantedLines === null) {
                    continue;
                }

                $lines = self::positiveLines($record);

                if ($type === 'coverage') {
                    $ordinal = self::ordinal($record, 'test');
                    $id = $tests[$ordinal] ?? null;

                    if ($id === null) {
                        throw new TestCoverageMapError('The per-test coverage map references an unknown test.');
                    }

                    foreach ($lines as $coveredLine) {
                        if (isset($wantedLines[$coveredLine])) {
                            $attributed[$file][$coveredLine] = true;
                            $impacted[$id] = true;
                        }
                    }

                    continue;
                }

                if ($type === 'unattributed') {
                    foreach ($lines as $unattributedLine) {
                        if (isset($wantedLines[$unattributedLine])) {
                            $unattributed[$file][$unattributedLine] = true;
                        }
                    }

                    continue;
                }

                $covered = $record['covered'] ?? null;
                if (!\is_bool($covered)) {
                    throw new TestCoverageMapError('The per-test coverage map has an invalid source record.');
                }

                foreach ($lines as $sourceLine) {
                    if (!isset($wantedLines[$sourceLine])) {
                        continue;
                    }

                    if (isset($source[$file][$sourceLine]) && $source[$file][$sourceLine] !== $covered) {
                        throw new TestCoverageMapError('The per-test coverage map has conflicting source records.');
                    }

                    $source[$file][$sourceLine] = $covered;
                }
            }
        } finally {
            \fclose($stream);
        }

        if (!$metadata) {
            throw new TestCoverageMapError('The per-test coverage map has no metadata record.');
        }

        foreach ($wanted as $file => $lines) {
            foreach (\array_keys($lines) as $line) {
                if (($source[$file][$line] ?? null) !== true
                    || !isset($attributed[$file][$line])
                    || isset($unattributed[$file][$line])
                ) {
                    throw new TestCoverageMapError(\sprintf(
                        'The per-test coverage map cannot attribute changed line %d in "%s".',
                        $line,
                        $file,
                    ));
                }
            }
        }

        $ids = [];
        foreach (\array_keys($impacted) as $id) {
            if ($id !== '') {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /** @param array<string, mixed> $record */
    private static function validateMetadata(array $record, string $root, string $runId): void
    {
        if (($record['type'] ?? null) !== 'meta'
            || ($record['complete'] ?? null) !== true
            || self::requiredString($record, 'root') !== $root
            || self::requiredString($record, 'runId') !== $runId
        ) {
            throw new TestCoverageMapError('The per-test coverage map metadata is not valid for this project.');
        }
    }

    /** @param array<string, mixed> $record */
    private static function ordinal(array $record, string $key): int
    {
        $value = $record[$key] ?? null;

        if (!\is_int($value) || $value < 0) {
            throw new TestCoverageMapError('The per-test coverage map contains an invalid test ordinal.');
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $record
     * @return non-empty-string
     */
    private static function requiredString(array $record, string $key): string
    {
        $value = $record[$key] ?? null;

        if (!\is_string($value) || $value === '') {
            throw new TestCoverageMapError(\sprintf('The per-test coverage map has an invalid "%s" field.', $key));
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $record
     * @return list<positive-int>
     */
    private static function positiveLines(array $record): array
    {
        $lines = $record['lines'] ?? null;

        if (!\is_array($lines) || !\array_is_list($lines)) {
            throw new TestCoverageMapError('The per-test coverage map has an invalid lines field.');
        }

        $validated = [];
        foreach ($lines as $line) {
            if (!\is_int($line) || $line < 1) {
                throw new TestCoverageMapError('The per-test coverage map contains an invalid line number.');
            }

            $validated[] = $line;
        }

        return $validated;
    }

    /** @return array<string, mixed> */
    private static function decode(string $line): array
    {
        try {
            $decoded = \json_decode($line, true, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException $error) {
            throw new TestCoverageMapError('The per-test coverage map contains invalid JSON.', $error->getCode(), previous: $error);
        }

        if (!\is_array($decoded)) {
            throw new TestCoverageMapError('The per-test coverage map contains a non-object record.');
        }

        $record = [];
        foreach ($decoded as $key => $value) {
            if (!\is_string($key)) {
                throw new TestCoverageMapError('The per-test coverage map contains a non-string key.');
            }

            $record[$key] = $value;
        }

        return $record;
    }
}
