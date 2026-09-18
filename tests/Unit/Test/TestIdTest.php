<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Test;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Internal\Wire\InvalidWirePayload;
use Greenlight\Test\TestId;
use Greenlight\Tests\Support\JsonWire;

use function Greenlight\expect;

final class TestIdTest
{
    #[Test]
    public function rendersWithAndWithoutDataSetKey(): void
    {
        expect((string) new TestId('App\FooTest', 'bar'))->because('renders with and without data set key')->toBe('App\FooTest::bar');
        expect((string) new TestId('App\FooTest', 'bar', 'JPY has no minor unit'))->because('renders with and without data set key')
            ->toBe('App\FooTest::bar[JPY has no minor unit]');
    }

    #[Test]
    public function equalityCoversAllComponents(): void
    {
        $id = new TestId('App\FooTest', 'bar', 'k');

        expect($id->equals(new TestId('App\FooTest', 'bar', 'k')))->because('equality covers all components')->toBeTrue();
        expect($id->equals(new TestId('App\FooTest', 'bar')))->because('equality covers all components')->toBeFalse();
        expect($id->equals(new TestId('App\FooTest', 'baz', 'k')))->because('equality covers all components')->toBeFalse();
        expect($id->equals(new TestId('App\OtherTest', 'bar', 'k')))->because('equality covers all components')->toBeFalse();
    }

    #[Test]
    public function survivesTheWire(): void
    {
        $id = new TestId('App\FooTest', 'bar', 'key');
        $restored = TestId::fromWire(JsonWire::roundTrip($id->toWire()));

        expect($id->equals($restored))->because('survives the wire')->toBeTrue();
    }

    #[Test]
    public function rejectsInvalidWirePayloads(): void
    {
        expect()->calling(
            static fn(): TestId => TestId::fromWire(['class' => 'App\FooTest']),
        )->because('rejects invalid wire payloads')->toThrow(InvalidWirePayload::class);
        expect()->calling(
            static fn(): TestId => TestId::fromWire(['class' => '', 'method' => 'bar', 'dataSetKey' => null]),
        )->because('rejects invalid wire payloads')->toThrow(InvalidWirePayload::class);
        expect()->calling(
            static fn(): TestId => TestId::fromWire(['class' => 'App\FooTest', 'method' => 'bar', 'dataSetKey' => 42]),
        )->because('rejects invalid wire payloads')->toThrow(InvalidWirePayload::class);
    }

    #[Test]
    #[DataSet('invalidIdentifiers')]
    public function rejectsInvalidConstruction(string $class, string $method, string $message): void
    {
        expect()->calling(static fn(): TestId => new TestId($class, $method))
            ->because('a test ID MUST identify a class and method')
            ->toThrow(\InvalidArgumentException::class, message: $message);
    }

    /**
     * @return iterable<string, array{string, string, non-empty-string}>
     */
    public static function invalidIdentifiers(): iterable
    {
        yield 'empty class' => ['', 'bar', 'Test ID class must not be empty.'];
        yield 'empty method' => ['App\FooTest', '', 'Test ID method must not be empty.'];
    }
}
