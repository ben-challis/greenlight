<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\DiscoveryProviderNonPublic;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;

final class NonPublicProviderTest
{
    #[Test]
    // Deliberately broken to drive the runtime discovery-error path.
    // @phpstan-ignore-next-line greenlight.dataProvider.provider
    #[DataSet('privateProvider')]
    public function needsData(int $value): void
    {
        self::privateProvider();
        echo $value;
    }

    /**
     * @return iterable<string, array{int}>
     */
    private static function privateProvider(): iterable
    {
        yield 'one' => [1];
    }
}
