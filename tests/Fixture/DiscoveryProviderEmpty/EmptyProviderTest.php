<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\DiscoveryProviderEmpty;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;

final class EmptyProviderTest
{
    #[Test]
    #[DataSet('nothing')]
    public function needsData(int $value): void
    {
        echo $value;
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function nothing(): iterable
    {
        return [];
    }
}
