<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Runner;

use Greenlight\Attribute\Test;
use Greenlight\Core\Test\TestId;
use Greenlight\Core\Test\TestMetadata;
use Greenlight\Discovery\ExecutionPlan;
use Greenlight\Discovery\PlanEntry;
use Greenlight\Expect\Expect;
use Greenlight\Runner\PlanShard;

final readonly class PlanShardArchitectureTest
{
    #[Test]
    public function highBitChecksumsKeepTheSameShardAcrossIntegerWidths(): void
    {
        $class = 'Acme\\BetaTest';
        $entry = new PlanEntry(new TestId($class, 'probe'), new TestMetadata($class, 'probe'));

        Expect::that(\hash('crc32b', $class))
            ->because('the portability regression MUST use a CRC32 value with its high bit set')
            ->toBe('d795afeb');
        Expect::that(PlanShard::select(
            new ExecutionPlan([$entry]),
            index: 1_616_911_340,
            count: 2_000_000_000,
        )->entries)
            ->because('shard selection MUST use the unsigned CRC32 value on each PHP integer width')
            ->toBe([$entry]);
    }
}
