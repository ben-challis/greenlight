<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Core;

use Greenlight\Attribute\Test;
use Greenlight\Core\Test\TestId;
use Greenlight\Core\Wire\InvalidWirePayload;
use Greenlight\Tests\Support\Check;

final class TestIdTest
{
    #[Test]
    public function rendersWithAndWithoutDataSetKey(): void
    {
        Check::same('App\FooTest::bar', (string) new TestId('App\FooTest', 'bar'), 'plain id');
        Check::same(
            'App\FooTest::bar[JPY has no minor unit]',
            (string) new TestId('App\FooTest', 'bar', 'JPY has no minor unit'),
            'data-set id',
        );
    }

    #[Test]
    public function equalityCoversAllComponents(): void
    {
        $id = new TestId('App\FooTest', 'bar', 'k');

        Check::true($id->equals(new TestId('App\FooTest', 'bar', 'k')), 'identical ids to be equal');
        Check::true(!$id->equals(new TestId('App\FooTest', 'bar')), 'ids differing in data-set key to differ');
        Check::true(!$id->equals(new TestId('App\FooTest', 'baz', 'k')), 'ids differing in method to differ');
        Check::true(!$id->equals(new TestId('App\OtherTest', 'bar', 'k')), 'ids differing in class to differ');
    }

    #[Test]
    public function survivesTheWire(): void
    {
        $id = new TestId('App\FooTest', 'bar', 'key');
        $restored = TestId::fromWire(Check::jsonRoundTrip($id->toWire()));

        Check::true($id->equals($restored), 'wire round trip to preserve the id');
    }

    #[Test]
    public function rejectsInvalidWirePayloads(): void
    {
        Check::throws(
            static fn(): TestId => TestId::fromWire(['class' => 'App\FooTest']),
            InvalidWirePayload::class,
            'payload missing keys',
        );
        Check::throws(
            static fn(): TestId => TestId::fromWire(['class' => '', 'method' => 'bar', 'dataSetKey' => null]),
            InvalidWirePayload::class,
            'payload with empty class',
        );
        Check::throws(
            static fn(): TestId => TestId::fromWire(['class' => 'App\FooTest', 'method' => 'bar', 'dataSetKey' => 42]),
            InvalidWirePayload::class,
            'payload with non-string data-set key',
        );
    }
}
