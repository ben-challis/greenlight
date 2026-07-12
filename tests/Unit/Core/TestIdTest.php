<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Core;

use Greenlight\Attribute\Test;
use Greenlight\Core\Test\TestId;
use Greenlight\Core\Wire\InvalidWirePayload;
use Greenlight\Expect\Expect;
use Greenlight\Tests\Support\JsonWire;

final class TestIdTest
{
    #[Test]
    public function rendersWithAndWithoutDataSetKey(): void
    {
        Expect::that((string) new TestId('App\FooTest', 'bar'))->toBe('App\FooTest::bar');
        Expect::that((string) new TestId('App\FooTest', 'bar', 'JPY has no minor unit'))
            ->toBe('App\FooTest::bar[JPY has no minor unit]');
    }

    #[Test]
    public function equalityCoversAllComponents(): void
    {
        $id = new TestId('App\FooTest', 'bar', 'k');

        Expect::that($id->equals(new TestId('App\FooTest', 'bar', 'k')))->toBeTrue();
        Expect::that($id->equals(new TestId('App\FooTest', 'bar')))->toBeFalse();
        Expect::that($id->equals(new TestId('App\FooTest', 'baz', 'k')))->toBeFalse();
        Expect::that($id->equals(new TestId('App\OtherTest', 'bar', 'k')))->toBeFalse();
    }

    #[Test]
    public function survivesTheWire(): void
    {
        $id = new TestId('App\FooTest', 'bar', 'key');
        $restored = TestId::fromWire(JsonWire::roundTrip($id->toWire()));

        Expect::that($id->equals($restored))->toBeTrue();
    }

    #[Test]
    public function rejectsInvalidWirePayloads(): void
    {
        Expect::that(
            static fn(): TestId => TestId::fromWire(['class' => 'App\FooTest']),
        )->toThrow(InvalidWirePayload::class);
        Expect::that(
            static fn(): TestId => TestId::fromWire(['class' => '', 'method' => 'bar', 'dataSetKey' => null]),
        )->toThrow(InvalidWirePayload::class);
        Expect::that(
            static fn(): TestId => TestId::fromWire(['class' => 'App\FooTest', 'method' => 'bar', 'dataSetKey' => 42]),
        )->toThrow(InvalidWirePayload::class);
    }
}
