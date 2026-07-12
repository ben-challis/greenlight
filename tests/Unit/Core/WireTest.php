<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Core;

use Greenlight\Attribute\Test;
use Greenlight\Core\Wire\InvalidWirePayload;
use Greenlight\Core\Wire\Wire;
use Greenlight\Expect\Expect;

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

        Expect::that(Wire::string($payload, 's'))->toBe('text');
        Expect::that(Wire::nonEmptyString($payload, 's'))->toBe('text');
        Expect::that(Wire::nullableString($payload, 'n'))->toBe(null);
        Expect::that(Wire::int($payload, 'i'))->toBe(42);
        Expect::that(Wire::float($payload, 'f'))->toBe(1.5);
        Expect::that(Wire::float($payload, 'i'))->toBe(42.0);
        Expect::that(Wire::bool($payload, 'b'))->toBe(true);
        Expect::that(Wire::listOfStrings($payload, 'list'))->toBe(['a', 'b']);
        Expect::that(Wire::listOfMaps($payload, 'maps'))->toBe([['k' => 1]]);
        Expect::that(Wire::map($payload, 'map'))->toBe(['k' => 1]);
        Expect::that(Wire::nullableMap($payload, 'n'))->toBe(null);
    }

    #[Test]
    public function failuresNameTheOffendingKey(): void
    {
        try {
            Wire::string([], 'runId');
        } catch (InvalidWirePayload $e) {
            Expect::that($e->getMessage())->toContain('runId');
        }

        try {
            Wire::int(['count' => 'many'], 'count');
        } catch (InvalidWirePayload $e) {
            Expect::that($e->getMessage())->toContain('count');
            Expect::that($e->getMessage())->toContain('string');
        }
    }

    #[Test]
    public function rejectsWrongShapes(): void
    {
        Expect::that(static fn(): string => Wire::nonEmptyString(['k' => ''], 'k'))->toThrow(InvalidWirePayload::class);
        Expect::that(static fn(): int => Wire::int(['k' => 1.5], 'k'))->toThrow(InvalidWirePayload::class);
        Expect::that(static fn(): float => Wire::float(['k' => '1.5'], 'k'))->toThrow(InvalidWirePayload::class);
        Expect::that(static fn(): array => Wire::listOfStrings(['k' => ['a' => 'b']], 'k'))->toThrow(InvalidWirePayload::class);
        Expect::that(static fn(): array => Wire::listOfStrings(['k' => [1]], 'k'))->toThrow(InvalidWirePayload::class);
        Expect::that(static fn(): array => Wire::listOfMaps(['k' => ['x']], 'k'))->toThrow(InvalidWirePayload::class);
    }
}
