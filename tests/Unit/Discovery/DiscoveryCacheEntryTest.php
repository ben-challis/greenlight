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
                '/project/tests/Provider.php' => ['mtime' => 300, 'size' => 400],
            ],
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

        yield 'entry is not a map' => [[...$valid, 'entries' => ['not a map']]];

        yield 'entry key is not a string' => [[...$valid, 'entries' => [[0 => 'value']]]];

        yield 'dependencies are not a map' => [[...$valid, 'dependencies' => 'not a map']];

        yield 'dependency path is empty' => [[...$valid, 'dependencies' => ['' => ['mtime' => 300, 'size' => 400]]]];

        yield 'dependency stat is invalid' => [[...$valid, 'dependencies' => ['/project/tests/Provider.php' => ['mtime' => '300', 'size' => 400]]]];
    }
}
