<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Core;

use Greenlight\Attribute\DataSet;
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
        Expect::that(Wire::nullableString($payload, 's'))
            ->because('reads typed values')
            ->toBe('text');
        Expect::that(Wire::nullableInt($payload, 'n'))
            ->because('reads typed values')
            ->toBe(null);
        Expect::that(Wire::nullableInt($payload, 'i'))
            ->because('reads typed values')
            ->toBe(42);
        Expect::that(Wire::nullableFloat($payload, 'n'))
            ->because('reads typed values')
            ->toBe(null);
        Expect::that(Wire::nullableFloat($payload, 'i'))
            ->because('reads typed values')
            ->toBe(42.0);
        Expect::that(Wire::nullableFloat($payload, 'f'))
            ->because('reads typed values')
            ->toBe(1.5);
        Expect::that(Wire::int($payload, 'i'))->because('reads typed values')->toBe(42);
        Expect::that(Wire::float($payload, 'f'))->because('reads typed values')->toBe(1.5);
        Expect::that(Wire::float($payload, 'i'))->because('reads typed values')->toBe(42.0);
        Expect::that(Wire::bool($payload, 'b'))->because('reads typed values')->toBe(true);
        Expect::that(Wire::listOfStrings($payload, 'list'))->because('reads typed values')->toBe(['a', 'b']);
        Expect::that(Wire::nullableListOfStrings($payload, 'n'))
            ->because('reads typed values')
            ->toBe(null);
        Expect::that(Wire::nullableListOfStrings($payload, 'list'))
            ->because('reads typed values')
            ->toBe(['a', 'b']);
        Expect::that(Wire::listOfMaps($payload, 'maps'))->because('reads typed values')->toBe([['k' => 1]]);
        Expect::that(Wire::map($payload, 'map'))->because('reads typed values')->toBe(['k' => 1]);
        Expect::that(Wire::nullableMap($payload, 'n'))->because('reads typed values')->toBe(null);
        Expect::that(Wire::nullableMap($payload, 'map'))
            ->because('reads typed values')
            ->toBe(['k' => 1]);
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

    #[Test]
    #[DataSet('invalidReaderCases')]
    public function typedReadersRejectInvalidFields(string $reader, string $expected): void
    {
        $payload = ['field' => new \stdClass()];
        $read = match ($reader) {
            'string' => static fn(): string => Wire::string($payload, 'field'),
            'nullableString' => static fn(): ?string => Wire::nullableString($payload, 'field'),
            'nullableInt' => static fn(): ?int => Wire::nullableInt($payload, 'field'),
            'nullableFloat' => static fn(): ?float => Wire::nullableFloat($payload, 'field'),
            'bool' => static fn(): bool => Wire::bool($payload, 'field'),
            'map' => static fn(): array => Wire::map($payload, 'field'),
            'nullableMap' => static fn(): ?array => Wire::nullableMap($payload, 'field'),
            'listOfMaps' => static fn(): array => Wire::listOfMaps($payload, 'field'),
            default => throw new \LogicException('Unknown wire reader.'),
        };

        Expect::that($read)
            ->because('typed wire readers reject invalid fields')
            ->toThrow(
                InvalidWirePayload::class,
                message: \sprintf(
                    'Wire payload key "field" must be %s, got stdClass.',
                    $expected,
                ),
            );
    }

    /**
     * @return iterable<string, array{non-empty-string, non-empty-string}>
     */
    public static function invalidReaderCases(): iterable
    {
        yield 'string' => ['string', 'a string'];
        yield 'nullable string' => ['nullableString', 'a string or null'];
        yield 'nullable integer' => ['nullableInt', 'an integer or null'];
        yield 'nullable float' => ['nullableFloat', 'a float or null'];
        yield 'boolean' => ['bool', 'a boolean'];
        yield 'map' => ['map', 'a map'];
        yield 'nullable map' => ['nullableMap', 'a map'];
        yield 'list of maps' => ['listOfMaps', 'a list of maps'];
    }
}
