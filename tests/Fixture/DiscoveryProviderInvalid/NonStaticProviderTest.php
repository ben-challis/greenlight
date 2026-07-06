<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\DiscoveryProviderInvalid;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;

final class NonStaticProviderTest
{
    #[Test]
    #[DataSet('instanceProvider')]
    public function needsData(int $value): void
    {
        echo $value;
    }

    /**
     * @return iterable<string, array{int}>
     */
    public function instanceProvider(): iterable
    {
        yield 'one' => [1];
    }
}
