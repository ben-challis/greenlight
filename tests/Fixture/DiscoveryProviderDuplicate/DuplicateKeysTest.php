<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\DiscoveryProviderDuplicate;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;

final class DuplicateKeysTest
{
    #[Test]
    #[DataSet('repeats')]
    public function needsData(int $value): void
    {
        echo $value;
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function repeats(): iterable
    {
        yield 'same key' => [1];

        yield 'same key' => [2];
    }
}
