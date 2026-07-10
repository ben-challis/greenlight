<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\DiscoveryProviderNotIterable;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;

final class NotIterableProviderTest
{
    #[Test]
    // @phpstan-ignore greenlight.dataProvider.returnType (deliberately broken: drives the runtime discovery-error path)
    #[DataSet('notIterable')]
    public function needsData(string $value): void
    {
        echo $value;
    }

    public static function notIterable(): string
    {
        return 'not a data set';
    }
}
