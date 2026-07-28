<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Harness;

use Greenlight\Attribute\Test;
use Greenlight\Core\Wire\InvalidWirePayload;
use Greenlight\Expect\Expect;
use Greenlight\Harness\IntegrationResources;

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
}
