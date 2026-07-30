<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Core;

use Greenlight\Attribute\Test;
use Greenlight\Core\Test\TestMetadata;
use Greenlight\Core\Wire\InvalidWirePayload;
use Greenlight\Expect\Expect;

final readonly class TestMetadataNullSkipUnlessArgumentsWireTest
{
    #[Test]
    public function explicitNullSkipUnlessArgumentsAreRejected(): void
    {
        $payload = new TestMetadata('App\ExampleTest', 'checksValue')->toWire();
        $payload['skipUnlessArguments'] = null;

        Expect::that(static fn(): TestMetadata => TestMetadata::fromWire($payload))
            ->because('explicit null is not the backward-compatible missing-key default')
            ->toThrow(
                InvalidWirePayload::class,
                message: 'Wire payload key "skipUnlessArguments" must be a list of scalars or nulls, got null.',
            );
    }
}
