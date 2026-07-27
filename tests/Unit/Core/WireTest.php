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

        Expect::that(Wire::string($payload, 's'))->because('reads typed values')->toBe('text');
        Expect::that(Wire::nonEmptyString($payload, 's'))->because('reads typed values')->toBe('text');
        Expect::that(Wire::nullableString($payload, 'n'))->because('reads typed values')->toBe(null);
        Expect::that(Wire::int($payload, 'i'))->because('reads typed values')->toBe(42);
        Expect::that(Wire::float($payload, 'f'))->because('reads typed values')->toBe(1.5);
        Expect::that(Wire::float($payload, 'i'))->because('reads typed values')->toBe(42.0);
        Expect::that(Wire::bool($payload, 'b'))->because('reads typed values')->toBe(true);
        Expect::that(Wire::listOfStrings($payload, 'list'))->because('reads typed values')->toBe(['a', 'b']);
        Expect::that(Wire::listOfMaps($payload, 'maps'))->because('reads typed values')->toBe([['k' => 1]]);
        Expect::that(Wire::map($payload, 'map'))->because('reads typed values')->toBe(['k' => 1]);
        Expect::that(Wire::nullableMap($payload, 'n'))->because('reads typed values')->toBe(null);
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
        Expect::that(static fn(): string => Wire::nonEmptyString(['k' => ''], 'k'))->because('rejects wrong shapes')->toThrow(InvalidWirePayload::class);
        Expect::that(static fn(): int => Wire::int(['k' => 1.5], 'k'))->because('rejects wrong shapes')->toThrow(InvalidWirePayload::class);
        Expect::that(static fn(): float => Wire::float(['k' => '1.5'], 'k'))->because('rejects wrong shapes')->toThrow(InvalidWirePayload::class);
        Expect::that(static fn(): array => Wire::listOfStrings(['k' => ['a' => 'b']], 'k'))->because('rejects wrong shapes')->toThrow(InvalidWirePayload::class);
        Expect::that(static fn(): array => Wire::listOfStrings(['k' => [1]], 'k'))->because('rejects wrong shapes')->toThrow(InvalidWirePayload::class);
        Expect::that(static fn(): array => Wire::listOfMaps(['k' => ['x']], 'k'))->because('rejects wrong shapes')->toThrow(InvalidWirePayload::class);
    }
}
