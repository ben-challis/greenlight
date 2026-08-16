<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Core;

use Greenlight\Attribute\Test;
use Greenlight\Core\Test\TestMetadata;
use Greenlight\Expect\Expect;
use Greenlight\Tests\Support\JsonWire;

final readonly class TestMetadataZeroSkipReasonTest
{
    #[Test]
    public function retainsAZeroSkipReasonAcrossTheWire(): void
    {
        $metadata = new TestMetadata('App\ExampleTest', 'example', skipReason: '0');
        $decoded = TestMetadata::fromWire(JsonWire::roundTrip($metadata->toWire()));

        Expect::that($metadata->skipReason)
            ->because('test metadata MUST retain each non-empty skip reason')
            ->toBe('0');
        Expect::that($decoded->skipReason)
            ->because('the skip reason MUST survive the wire')
            ->toBe('0');
    }
}
