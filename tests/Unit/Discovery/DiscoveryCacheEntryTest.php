<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Discovery;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Discovery\DiscoveryCacheEntry;

use function Greenlight\expect;

final class DiscoveryCacheEntryTest
{
    #[Test]
    public function aValidDecodedEntrySerializesToTheSameShape(): void
    {
        $decoded = [
            'mtime' => 100,
            'size' => 200,
            'entries' => [['class' => 'Example\Test']],
            'dependencies' => [
                '/project/tests/Provider.php' => [
                    'mtime' => 300,
                    'size' => 400,
                    'contentHash' => \str_repeat('b', 40),
                ],
            ],
            'contentHash' => \str_repeat('a', 40),
        ];

        $entry = DiscoveryCacheEntry::fromDecoded($decoded);

        expect($entry)
            ->because('The decoded discovery cache entry MUST be valid.')
            ->toBeInstanceOf(DiscoveryCacheEntry::class);

        expect($entry->jsonSerialize())
            ->because('a valid decoded entry serializes to the same shape')
            ->toBe($decoded);
    }

    #[Test]
    public function aLegacyEntryWithoutDependenciesUsesTheBackwardCompatibleDefault(): void
    {
        $entry = DiscoveryCacheEntry::fromDecoded([
            'mtime' => 100,
            'size' => 200,
            'entries' => [['class' => 'Example\Test']],
        ]);

        expect($entry)
            ->because('The legacy discovery cache entry MUST be valid.')
            ->toBeInstanceOf(DiscoveryCacheEntry::class);

        expect($entry->dependencies)
            ->because('a legacy entry without dependencies MUST use an empty dependency map')
            ->toBe([]);
    }

    /**
     * @param array<mixed> $decoded
     */
    #[Test]
    #[DataSet('malformedDecodedEntries')]
    public function aMalformedDecodedEntryIsRejected(array $decoded): void
    {
        expect(DiscoveryCacheEntry::fromDecoded($decoded))
            ->because('a malformed decoded entry is rejected')
            ->toBeNull();
    }

    #[Test]
    public function anUndecodablePlanEntryBecomesACacheMiss(): void
    {
        $entry = new DiscoveryCacheEntry(100, 200, [[]]);

        expect($entry->planEntries())
            ->because('an undecodable plan entry MUST become a cache miss')
            ->toBeNull();
    }

    /**
     * @return iterable<string, array{array<mixed>}>
     */
    public static function malformedDecodedEntries(): iterable
    {
        $valid = [
            'mtime' => 100,
            'size' => 200,
            'entries' => [['class' => 'Example\Test']],
            'dependencies' => [
                '/project/tests/Provider.php' => ['mtime' => 300, 'size' => 400],
            ],
        ];

        yield 'invalid top-level field' => [\array_replace($valid, ['mtime' => '100'])];

        yield 'invalid content hash' => [\array_replace($valid, ['contentHash' => \str_repeat('g', 40)])];

        yield 'content hash with trailing newline' => [\array_replace($valid, [
            'contentHash' => \str_repeat('a', 40) . "\n",
        ])];

        yield 'entry is not a map' => [\array_replace($valid, ['entries' => ['not a map']])];

        yield 'entries are not a list' => [\array_replace($valid, ['entries' => [
            'first' => ['class' => 'Example\\Test'],
        ]])];

        yield 'entry key is not a string' => [\array_replace($valid, ['entries' => [[0 => 'value']]])];

        yield 'dependencies are not a map' => [\array_replace($valid, ['dependencies' => 'not a map'])];

        yield 'dependency path is not a string' => [\array_replace($valid, ['dependencies' => [
            0 => ['mtime' => 300, 'size' => 400],
            '/project/tests/Provider.php' => ['mtime' => 300, 'size' => 400],
        ]])];

        yield 'dependency path is empty' => [\array_replace($valid, ['dependencies' => ['' => ['mtime' => 300, 'size' => 400]]])];

        yield 'dependency stat is invalid' => [\array_replace($valid, ['dependencies' => ['/project/tests/Provider.php' => ['mtime' => '300', 'size' => 400]]])];

        yield 'dependency content hash is invalid' => [\array_replace($valid, [
            'dependencies' => [
                '/project/tests/Provider.php' => [
                    'mtime' => 300,
                    'size' => 400,
                    'contentHash' => \str_repeat('g', 40),
                ],
            ],
        ])];

        yield 'dependency content hash has trailing newline' => [\array_replace($valid, [
            'dependencies' => [
                '/project/tests/Provider.php' => [
                    'mtime' => 300,
                    'size' => 400,
                    'contentHash' => \str_repeat('b', 40) . "\n",
                ],
            ],
        ])];
    }
}
