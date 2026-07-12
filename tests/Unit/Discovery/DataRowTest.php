<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Discovery;

use Greenlight\Attribute\Test;
use Greenlight\Discovery\DataSetExpander;
use Greenlight\Discovery\DiscoveryError;
use Greenlight\Discovery\Filter;
use Greenlight\Discovery\TestDiscoverer;
use Greenlight\Expect\Expect;
use Greenlight\Tests\Fixture\DataRows\InlineRowsTest;
use Greenlight\Tests\Fixture\DataRowsConflict\DuplicateRowKeyTest;

final class DataRowTest
{
    #[Test]
    public function inlineRowsExpandWithLabelsAndPositions(): void
    {
        $rows = new DataSetExpander()->rowsFor(new \ReflectionClass(InlineRowsTest::class), 'addsUp', null, 5.0);

        Expect::that(\array_keys($rows))->toBe(['small', '#1'])
            ->and($rows['small'])->toBe([1, 2, 3])
            ->and($rows['#1'])->toBe([10, 20, 30]);
    }

    #[Test]
    public function inlineRowsAndProviderRowsShareOneKeySpace(): void
    {
        $rows = new DataSetExpander()->rowsFor(
            new \ReflectionClass(InlineRowsTest::class),
            'acceptsWord',
            'providedWords',
            5.0,
        );

        Expect::that(\array_keys($rows))->toBe(['from attribute', 'from provider']);
    }

    #[Test]
    public function duplicateKeysBetweenInlineAndProviderAreRefused(): void
    {
        $reflection = new \ReflectionClass(DuplicateRowKeyTest::class);

        Expect::that(
            static fn(): array => new DataSetExpander()->rowsFor($reflection, 'probe', 'rows', 5.0),
        )->toThrow(DiscoveryError::class, '/twice/');
    }

    #[Test]
    public function discovererExpandsInlineRowsIntoThePlan(): void
    {
        $plan = new TestDiscoverer()->discover(
            [\dirname(__DIR__, 2) . '/Fixture/DataRows'],
            new Filter(includeMethods: ['addsUp']),
        );

        $ids = \array_map(static fn($entry): string => (string) $entry->id, $plan->entries);

        Expect::that($ids)->toBe([
            'Greenlight\Tests\Fixture\DataRows\InlineRowsTest::addsUp[small]',
            'Greenlight\Tests\Fixture\DataRows\InlineRowsTest::addsUp[#1]',
        ]);
    }
}
