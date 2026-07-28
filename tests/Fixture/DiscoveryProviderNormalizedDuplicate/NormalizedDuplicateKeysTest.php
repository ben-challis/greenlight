<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\DiscoveryProviderNormalizedDuplicate;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;

final class NormalizedDuplicateKeysTest
{
    #[Test]
    #[DataSet('rows')]
    public function needsData(int $value): void
    {
        echo $value;
    }

    /**
     * @return iterable<int|string, array{int}>
     */
    public static function rows(): iterable
    {
        yield 1 => [1];

        yield '#1' => [2];
    }
}
