<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\DiscoveryProviderSlow;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;

final class SlowProviderTest
{
    #[Test]
    #[DataSet('dawdles')]
    public function needsData(int $value): void
    {
        echo $value;
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function dawdles(): iterable
    {
        foreach ([1, 2, 3] as $i) {
            \usleep(10_000);

            yield \sprintf('case %d', $i) => [$i];
        }
    }
}
