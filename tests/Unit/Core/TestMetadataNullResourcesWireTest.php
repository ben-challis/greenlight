<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Core;

use Greenlight\Attribute\Test;
use Greenlight\Core\Test\TestMetadata;
use Greenlight\Core\Wire\InvalidWirePayload;
use Greenlight\Expect\Expect;

final readonly class TestMetadataNullResourcesWireTest
{
    #[Test]
    public function explicitNullResourcesAreRejected(): void
    {
        $payload = new TestMetadata('App\ExampleTest', 'checksValue')->toWire();
        $payload['resources'] = null;

        Expect::that(static fn(): TestMetadata => TestMetadata::fromWire($payload))
            ->because('explicit null is not the backward-compatible missing-key default')
            ->toThrow(
                InvalidWirePayload::class,
                message: 'Wire payload key "resources" must be a list of strings, got null.',
            );
    }
}
