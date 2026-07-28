<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Discovery;

use Greenlight\Attribute\Test;
use Greenlight\Discovery\DataSetExpander;
use Greenlight\Expect\Expect;
use Greenlight\Tests\Fixture\Discovery\FakeMonotonicClock;
use Greenlight\Tests\Fixture\DiscoveryDataSets\ProviderKeysTest;

final readonly class DataSetBudgetBoundaryTest
{
    #[Test]
    public function providerMayFinishExactlyAtItsDeadline(): void
    {
        $deadline = 5_000_000_000;
        $clock = new FakeMonotonicClock(0, $deadline, $deadline, $deadline);
        $rows = new DataSetExpander($clock)->rowsFor(
            new \ReflectionClass(ProviderKeysTest::class),
            'withStringKeys',
            'stringKeys',
            5.0,
        );

        Expect::that($rows)
            ->because('a provider MUST exceed its time budget before discovery rejects it')
            ->toBe([
                'first case' => ['a'],
                'second case' => ['b'],
            ]);
    }
}
