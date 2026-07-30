<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Discovery;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Discovery\DiscoveryCacheEntry;
use Greenlight\Expect\Expect;
use Greenlight\Expect\Fail;

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

        if (!$entry instanceof DiscoveryCacheEntry) {
            Fail::because('Expected a valid decoded discovery cache entry.');
        }

        Expect::that($entry->jsonSerialize())
            ->because('a valid decoded entry serializes to the same shape')
            ->toBe($decoded);
    }

    /**
     * @param array<mixed> $decoded
     */
    #[Test]
    #[DataSet('malformedDecodedEntries')]
    public function aMalformedDecodedEntryIsRejected(array $decoded): void
    {
        Expect::that(DiscoveryCacheEntry::fromDecoded($decoded))
            ->because('a malformed decoded entry is rejected')
            ->toBeNull();
    }

    #[Test]
    public function anUndecodablePlanEntryBecomesACacheMiss(): void
    {
        $entry = new DiscoveryCacheEntry(100, 200, [[]]);

        Expect::that($entry->planEntries())
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

        yield 'invalid top-level field' => [[...$valid, 'mtime' => '100']];

        yield 'invalid content hash' => [[...$valid, 'contentHash' => \str_repeat('g', 40)]];

        yield 'entry is not a map' => [[...$valid, 'entries' => ['not a map']]];

        yield 'entry key is not a string' => [[...$valid, 'entries' => [[0 => 'value']]]];

        yield 'dependencies are not a map' => [[...$valid, 'dependencies' => 'not a map']];

        yield 'dependency path is not a string' => [[...$valid, 'dependencies' => [
            0 => ['mtime' => 300, 'size' => 400],
            '/project/tests/Provider.php' => ['mtime' => 300, 'size' => 400],
        ]]];

        yield 'dependency path is empty' => [[...$valid, 'dependencies' => ['' => ['mtime' => 300, 'size' => 400]]]];

        yield 'dependency stat is invalid' => [[...$valid, 'dependencies' => ['/project/tests/Provider.php' => ['mtime' => '300', 'size' => 400]]]];

        yield 'dependency content hash is invalid' => [[
            ...$valid,
            'dependencies' => [
                '/project/tests/Provider.php' => [
                    'mtime' => 300,
                    'size' => 400,
                    'contentHash' => \str_repeat('g', 40),
                ],
            ],
        ]];
    }
}
