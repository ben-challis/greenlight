<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\DiscoveryProviderMissing;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;

final class MissingProviderTest
{
    #[Test]
    // @phpstan-ignore greenlight.dataProvider.provider (deliberately broken: drives the runtime discovery-error path)
    #[DataSet('doesNotExist')]
    public function needsData(int $value): void
    {
        echo $value;
    }
}
