<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\IntegrationFixture;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\IntegrationFixture\FixtureResource;

final readonly class FixtureResourceAccessTest
{
    #[Test]
    public function hasFindsOrdinaryValuesAndSecrets(): void
    {
        $resource = FixtureResource::from(
            ['host' => 'database'],
            ['password' => 'secret'],
        );

        Expect::that($resource->has('host'))
            ->because('ordinary fixture values MUST be discoverable')
            ->toBeTrue();
        Expect::that($resource->has('password'))
            ->because('fixture secrets MUST be discoverable without revealing them')
            ->toBeTrue();
        Expect::that($resource->has('missing'))
            ->because('unknown fixture keys MUST remain absent')
            ->toBeFalse();
    }

    #[Test]
    public function missingValuesAndSecretsAreReportedExactly(): void
    {
        $resource = FixtureResource::empty();

        Expect::that(static fn(): mixed => $resource->value('host'))
            ->because('missing ordinary values MUST identify their key')
            ->toThrow(
                \OutOfBoundsException::class,
                message: 'Fixture resource has no ordinary value named "host".',
            );
        Expect::that(static fn() => $resource->secret('password'))
            ->because('missing secrets MUST identify their key')
            ->toThrow(
                \OutOfBoundsException::class,
                message: 'Fixture resource has no secret named "password".',
            );
    }

    #[Test]
    public function emptyCollectionsCanBeReadAsListsOrMaps(): void
    {
        $resource = FixtureResource::fromWire(FixtureResource::from([
            'list' => [],
            'map' => [],
        ])->toWire());

        Expect::that($resource->list('list'))
            ->because('an empty transported collection MUST remain usable as a list')
            ->toBe([]);
        Expect::that($resource->map('map'))
            ->because('an empty transported collection MUST remain usable as a map')
            ->toBe([]);
    }

    #[Test]
    #[DataSet('wrongTypedValues')]
    public function typedAccessorsReportWrongValuesExactly(
        string $accessor,
        string $key,
        string $message,
    ): void {
        $resource = FixtureResource::from([
            'asString' => 42,
            'asInt' => '42',
            'asFloat' => false,
            'asBool' => 1,
            'asList' => ['key' => 'value'],
            'asMap' => ['value'],
        ]);

        Expect::that(static fn(): mixed => match ($accessor) {
            'string' => $resource->string($key),
            'int' => $resource->int($key),
            'float' => $resource->float($key),
            'bool' => $resource->bool($key),
            'list' => $resource->list($key),
            'map' => $resource->map($key),
            default => throw new \LogicException('The data set supplied an unknown fixture resource accessor.'),
        })
            ->because('typed fixture access MUST identify the key, expected type, and actual type')
            ->toThrow(\UnexpectedValueException::class, message: $message);
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function wrongTypedValues(): iterable
    {
        yield 'string accessor' => [
            'string',
            'asString',
            'Fixture resource value "asString" must be a string, got int.',
        ];
        yield 'integer accessor' => [
            'int',
            'asInt',
            'Fixture resource value "asInt" must be an integer, got string.',
        ];
        yield 'float accessor' => [
            'float',
            'asFloat',
            'Fixture resource value "asFloat" must be a float, got bool.',
        ];
        yield 'boolean accessor' => [
            'bool',
            'asBool',
            'Fixture resource value "asBool" must be a boolean, got int.',
        ];
        yield 'list accessor' => [
            'list',
            'asList',
            'Fixture resource value "asList" must be a list, got array.',
        ];
        yield 'map accessor' => [
            'map',
            'asMap',
            'Fixture resource value "asMap" must be a map, got array.',
        ];
    }
}
