<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Test;

use Greenlight\Attribute\Test;
use Greenlight\Internal\Wire\InvalidWirePayload;
use Greenlight\Test\DataProvider;
use Greenlight\Tests\Support\JsonWire;

use function Greenlight\expect;

final readonly class DataProviderTest
{
    #[Test]
    public function survivesTheWire(): void
    {
        $provider = new DataProvider('rows', 'App\SharedRows');
        $restored = DataProvider::fromWire(JsonWire::roundTrip($provider->toWire()));

        expect($restored->toWire())
            ->because('the data provider MUST survive the wire')
            ->toBe($provider->toWire());
    }

    #[Test]
    public function externalClassRequiresAMethodOnBothSides(): void
    {
        expect()->calling(static fn(): DataProvider => new DataProvider(class: 'App\SharedRows'))
            ->because('a direct external data provider MUST name its method')
            ->toThrow(\InvalidArgumentException::class);

        $payload = new DataProvider()->toWire();
        $payload['class'] = 'App\SharedRows';

        expect()->calling(static fn(): DataProvider => DataProvider::fromWire($payload))
            ->because('a wire external data provider MUST name its method')
            ->toThrow(InvalidWirePayload::class);
    }
}
