<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Core;

use Greenlight\Attribute\Test;
use Greenlight\Core\Wire\InvalidWirePayload;
use Greenlight\Core\Wire\Wire;
use Greenlight\Tests\Support\Check;

final class WireTest
{
    #[Test]
    public function readsTypedValues(): void
    {
        $payload = [
            's' => 'text',
            'n' => null,
            'i' => 42,
            'f' => 1.5,
            'b' => true,
            'list' => ['a', 'b'],
            'maps' => [['k' => 1]],
            'map' => ['k' => 1],
        ];

        Check::same('text', Wire::string($payload, 's'), 'string');
        Check::same('text', Wire::nonEmptyString($payload, 's'), 'non-empty string');
        Check::same(null, Wire::nullableString($payload, 'n'), 'nullable string');
        Check::same(42, Wire::int($payload, 'i'), 'int');
        Check::same(1.5, Wire::float($payload, 'f'), 'float');
        Check::same(42.0, Wire::float($payload, 'i'), 'float from int');
        Check::same(true, Wire::bool($payload, 'b'), 'bool');
        Check::same(['a', 'b'], Wire::listOfStrings($payload, 'list'), 'list of strings');
        Check::same([['k' => 1]], Wire::listOfMaps($payload, 'maps'), 'list of maps');
        Check::same(['k' => 1], Wire::map($payload, 'map'), 'map');
        Check::same(null, Wire::nullableMap($payload, 'n'), 'nullable map');
    }

    #[Test]
    public function failuresNameTheOffendingKey(): void
    {
        try {
            Wire::string([], 'runId');
        } catch (InvalidWirePayload $e) {
            Check::true(\str_contains($e->getMessage(), 'runId'), 'missing-key message to name the key');
        }

        try {
            Wire::int(['count' => 'many'], 'count');
        } catch (InvalidWirePayload $e) {
            Check::true(\str_contains($e->getMessage(), 'count'), 'type message to name the key');
            Check::true(\str_contains($e->getMessage(), 'string'), 'type message to name the actual type');
        }
    }

    #[Test]
    public function rejectsWrongShapes(): void
    {
        Check::throws(static fn(): string => Wire::nonEmptyString(['k' => ''], 'k'), InvalidWirePayload::class, 'empty string');
        Check::throws(static fn(): int => Wire::int(['k' => 1.5], 'k'), InvalidWirePayload::class, 'float as int');
        Check::throws(static fn(): float => Wire::float(['k' => '1.5'], 'k'), InvalidWirePayload::class, 'string as float');
        Check::throws(static fn(): array => Wire::listOfStrings(['k' => ['a' => 'b']], 'k'), InvalidWirePayload::class, 'map as list');
        Check::throws(static fn(): array => Wire::listOfStrings(['k' => [1]], 'k'), InvalidWirePayload::class, 'ints in string list');
        Check::throws(static fn(): array => Wire::listOfMaps(['k' => ['x']], 'k'), InvalidWirePayload::class, 'strings in map list');
    }
}
