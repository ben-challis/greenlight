<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Core;

use Greenlight\Attribute\Test;
use Greenlight\Core\Test\TestMetadata;
use Greenlight\Expect\Expect;
use Greenlight\Tests\Support\JsonWire;

final readonly class TestMetadataZeroGroupTest
{
    #[Test]
    public function retainsAZeroGroupAcrossTheWire(): void
    {
        $metadata = new TestMetadata('App\ExampleTest', 'example', groups: ['0']);
        $decoded = TestMetadata::fromWire(JsonWire::roundTrip($metadata->toWire()));

        Expect::that($metadata->groups)
            ->because('test metadata MUST retain each non-empty group name')
            ->toBe(['0'])
            ->and($decoded->groups)
            ->because('the group name MUST survive the wire')
            ->toBe(['0']);
    }
}
