<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Internal\Wire;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Internal\Wire\InvalidWirePayload;
use Greenlight\Internal\Wire\Wire;
use Greenlight\Result\Outcome;

use function Greenlight\expect;

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

        expect(Wire::string($payload, 's'))->because('reads typed values')->toBe('text');
        expect(Wire::nonEmptyString($payload, 's'))->because('reads typed values')->toBe('text');
        expect(Wire::nullableString($payload, 'n'))->because('reads typed values')->toBe(null);
        expect(Wire::nullableString($payload, 's'))
            ->because('reads typed values')
            ->toBe('text');
        expect(Wire::nullableInt($payload, 'n'))
            ->because('reads typed values')
            ->toBe(null);
        expect(Wire::nullableInt($payload, 'i'))
            ->because('reads typed values')
            ->toBe(42);
        expect(Wire::nullableFloat($payload, 'n'))
            ->because('reads typed values')
            ->toBe(null);
        expect(Wire::nullableFloat($payload, 'i'))
            ->because('reads typed values')
            ->toBe(42.0);
        expect(Wire::nullableFloat($payload, 'f'))
            ->because('reads typed values')
            ->toBe(1.5);
        expect(Wire::int($payload, 'i'))->because('reads typed values')->toBe(42);
        expect(Wire::float($payload, 'f'))->because('reads typed values')->toBe(1.5);
        expect(Wire::float($payload, 'i'))->because('reads typed values')->toBe(42.0);
        expect(Wire::bool($payload, 'b'))->because('reads typed values')->toBe(true);
        expect(Wire::listOfStrings($payload, 'list'))->because('reads typed values')->toBe(['a', 'b']);
        expect(Wire::nullableListOfStrings($payload, 'n'))
            ->because('reads typed values')
            ->toBe(null);
        expect(Wire::nullableListOfStrings($payload, 'list'))
            ->because('reads typed values')
            ->toBe(['a', 'b']);
        expect(Wire::listOfMaps($payload, 'maps'))->because('reads typed values')->toBe([['k' => 1]]);
        expect(Wire::map($payload, 'map'))->because('reads typed values')->toBe(['k' => 1]);
        expect(Wire::nullableMap($payload, 'n'))->because('reads typed values')->toBe(null);
        expect(Wire::nullableMap($payload, 'map'))
            ->because('reads typed values')
            ->toBe(['k' => 1]);
    }

    #[Test]
    public function failuresNameTheOffendingKey(): void
    {
        expect()->calling(static fn(): string => Wire::string([], 'runId'))
            ->because('a missing field MUST name its wire key')
            ->toThrow(
                InvalidWirePayload::class,
                message: 'Wire payload is missing the "runId" key.',
            );
        expect()->calling(static fn(): int => Wire::int(['count' => 'many'], 'count'))
            ->because('an invalid field MUST name its wire key and actual type')
            ->toThrow(
                InvalidWirePayload::class,
                message: 'Wire payload key "count" must be an integer, got string.',
            );
    }

    #[Test]
    public function rejectsWrongShapes(): void
    {
        expect()->calling(static fn(): string => Wire::nonEmptyString(['k' => ''], 'k'))->because('rejects wrong shapes')->toThrow(InvalidWirePayload::class);
        expect()->calling(static fn(): int => Wire::int(['k' => 1.5], 'k'))->because('rejects wrong shapes')->toThrow(InvalidWirePayload::class);
        expect()->calling(static fn(): float => Wire::float(['k' => '1.5'], 'k'))->because('rejects wrong shapes')->toThrow(InvalidWirePayload::class);
        expect()->calling(static fn(): array => Wire::listOfStrings(['k' => ['a' => 'b']], 'k'))->because('rejects wrong shapes')->toThrow(InvalidWirePayload::class);
        expect()->calling(static fn(): array => Wire::listOfStrings(['k' => [1]], 'k'))->because('rejects wrong shapes')->toThrow(InvalidWirePayload::class);
        expect()->calling(static fn(): array => Wire::listOfMaps(['k' => ['x']], 'k'))->because('rejects wrong shapes')->toThrow(InvalidWirePayload::class);
    }

    #[Test]
    public function mapReadersRejectNonEmptyLists(): void
    {
        $list = ['field' => ['first', 'second']];
        $listOfLists = ['field' => [['first']]];

        expect()->calling(static fn(): array => Wire::map($list, 'field'))
            ->because('a non-empty list is not a wire map')
            ->toThrow(
                InvalidWirePayload::class,
                message: 'Wire payload key "field" must be a map, got array.',
            );
        expect()->calling(static fn(): ?array => Wire::nullableMap($list, 'field'))
            ->because('a nullable wire map MUST validate its non-null shape')
            ->toThrow(
                InvalidWirePayload::class,
                message: 'Wire payload key "field" must be a map, got array.',
            );
        expect()->calling(static fn(): array => Wire::listOfMaps($listOfLists, 'field'))
            ->because('each item in a wire list of maps MUST be a map')
            ->toThrow(
                InvalidWirePayload::class,
                message: 'Wire payload key "field" must be a list of maps, got array.',
            );
    }

    #[Test]
    public function mapReadersKeepEmptyMaps(): void
    {
        $emptyMap = ['field' => []];

        expect(Wire::map($emptyMap, 'field'))
            ->because('an empty decoded JSON object is a valid wire map')
            ->toBe([]);
        expect(Wire::nullableMap($emptyMap, 'field'))
            ->toBe([]);
        expect(Wire::listOfMaps(['field' => [[]]], 'field'))
            ->toBe([[]]);
    }

    #[Test]
    public function mapReadersRejectNumericKeysInMixedMaps(): void
    {
        $map = ['field' => [0 => 'numeric', 'named' => 'value']];
        $listOfMaps = ['field' => [[0 => 'numeric', 'named' => 'value']]];

        expect()->calling(static fn(): array => Wire::map($map, 'field'))
            ->because('wire maps MUST have string keys')
            ->toThrow(
                InvalidWirePayload::class,
                message: 'Wire payload key "field" must be a map with string keys, got array.',
            );
        expect()->calling(static fn(): ?array => Wire::nullableMap($map, 'field'))
            ->because('nullable wire maps MUST validate non-null keys')
            ->toThrow(
                InvalidWirePayload::class,
                message: 'Wire payload key "field" must be a map with string keys, got array.',
            );
        expect()->calling(static fn(): array => Wire::listOfMaps($listOfMaps, 'field'))
            ->because('each wire map in a list MUST have string keys')
            ->toThrow(
                InvalidWirePayload::class,
                message: 'Wire payload key "field" must be a list of maps with string keys, got array.',
            );
    }

    #[Test]
    #[DataSet('nonFiniteFloats')]
    public function floatReadersRejectNonFiniteValues(float $value): void
    {
        $payload = ['durationSeconds' => $value];

        expect()->calling(static fn(): float => Wire::float($payload, 'durationSeconds'))
            ->because('protocol floats MUST be finite')
            ->toThrow(
                InvalidWirePayload::class,
                message: 'Wire payload key "durationSeconds" must be a finite float, got float.',
            );
        expect()->calling(static fn(): ?float => Wire::nullableFloat($payload, 'durationSeconds'))
            ->because('nullable protocol floats MUST reject non-finite values')
            ->toThrow(
                InvalidWirePayload::class,
                message: 'Wire payload key "durationSeconds" must be a finite float or null, got float.',
            );
    }

    #[Test]
    public function readsBackedEnumValues(): void
    {
        expect(Wire::enum(['outcome' => 'passed'], 'outcome', Outcome::class))
            ->because('wire enum values MUST resolve to their backed enum case')
            ->toBe(Outcome::Passed);
    }

    #[Test]
    #[DataSet('invalidEnumValues')]
    public function rejectsInvalidBackedEnumValues(string $value, string $expected): void
    {
        expect()->calling(
            static fn(): \BackedEnum => Wire::enum(['outcome' => $value], 'outcome', Outcome::class),
        )
            ->because('invalid wire enum values MUST identify the field constraint')
            ->toThrow(
                InvalidWirePayload::class,
                message: $expected,
            );
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

        expect()->calling($read)
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
        yield 'nullable float' => ['nullableFloat', 'a finite float or null'];
        yield 'boolean' => ['bool', 'a boolean'];
        yield 'map' => ['map', 'a map'];
        yield 'nullable map' => ['nullableMap', 'a map'];
        yield 'list of maps' => ['listOfMaps', 'a list of maps'];
    }

    /**
     * @return iterable<string, array{float}>
     */
    public static function nonFiniteFloats(): iterable
    {
        yield 'JSON exponent overflow' => [
            \json_decode('1e400', flags: \JSON_THROW_ON_ERROR),
        ];
        yield 'negative infinity' => [-\INF];
        yield 'not a number' => [\NAN];
    }

    /**
     * @return iterable<string, array{string, non-empty-string}>
     */
    public static function invalidEnumValues(): iterable
    {
        yield 'empty value' => [
            '',
            'Wire payload key "outcome" must be a non-empty string, got string.',
        ];
        yield 'unknown value' => [
            'unknown',
            'Wire payload key "outcome" must be a Greenlight\Result\Outcome value, got string.',
        ];
    }
}
