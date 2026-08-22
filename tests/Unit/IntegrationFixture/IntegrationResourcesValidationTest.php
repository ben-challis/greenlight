<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\IntegrationFixture;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\IntegrationFixture\FixtureResource;
use Greenlight\IntegrationFixture\IntegrationResources;
use Greenlight\Wire\InvalidWirePayload;

final readonly class IntegrationResourcesValidationTest
{
    #[Test]
    public function wireInputRejectsNonUtf8FixtureIdsAtTheWireBoundary(): void
    {
        Expect::that(static fn(): IntegrationResources => IntegrationResources::fromWire([
            'fixtures' => [
                "\xB1\x31" => [
                    'values' => [],
                    'secrets' => [],
                ],
            ],
        ]))
            ->because('invalid fixture IDs MUST remain protocol errors at the wire boundary')
            ->toThrow(
                InvalidWirePayload::class,
                message: 'Wire payload key "fixtures" must be a map of fixture resource payloads, got array.',
            );
    }

    #[Test]
    public function missingFixturesAreReportedExactly(): void
    {
        $resources = IntegrationResources::empty();

        Expect::that(static fn() => $resources->fixture('database'))
            ->because('a worker MUST identify a fixture that is not available to its channel')
            ->toThrow(
                \OutOfBoundsException::class,
                message: 'No integration fixture named "database" is available to this worker.',
            );
    }

    /**
     * @param array<mixed, mixed> $fixtures
     */
    #[Test]
    #[DataSet('invalidRuntimeFixtureMaps')]
    public function constructorRejectsInvalidRuntimeFixtureMaps(array $fixtures): void
    {
        $resources = new \ReflectionClass(IntegrationResources::class);

        Expect::that(static fn(): object => $resources->newInstance($fixtures))
            ->because('integration resources MUST validate runtime fixture maps at their boundary')
            ->toThrow(
                \InvalidArgumentException::class,
                message: 'Integration resources must be a map of non-empty UTF-8 fixture IDs '
                    . 'to FixtureResource instances.',
            );
    }

    /**
     * @return iterable<string, array{array<mixed, mixed>}>
     */
    public static function invalidRuntimeFixtureMaps(): iterable
    {
        yield 'integer fixture ID' => [[0 => FixtureResource::empty()]];
        yield 'empty fixture ID' => [['' => FixtureResource::empty()]];
        yield 'invalid resource type' => [['database' => new \stdClass()]];
    }
}
