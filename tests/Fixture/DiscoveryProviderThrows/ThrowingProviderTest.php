<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\DiscoveryProviderThrows;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;

final class ThrowingProviderTest
{
    #[Test]
    #[DataSet('boom')]
    public function needsData(int $value): void
    {
        echo $value;
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function boom(): iterable
    {
        throw new \RuntimeException('provider exploded');
    }
}
