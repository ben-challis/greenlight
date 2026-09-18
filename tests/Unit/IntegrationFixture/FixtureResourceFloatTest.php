<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\IntegrationFixture;

use Greenlight\Attribute\Test;
use Greenlight\IntegrationFixture\FixtureResource;

use function Greenlight\expect;

final readonly class FixtureResourceFloatTest
{
    #[Test]
    public function floatAccessNormalizesIntegerValues(): void
    {
        $resource = FixtureResource::from(['ratio' => 42]);

        expect($resource->float('ratio'))
            ->because('float fixture access MUST normalize integer values')
            ->toBe(42.0);
    }
}
