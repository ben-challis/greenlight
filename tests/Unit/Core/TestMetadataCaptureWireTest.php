<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Core;

use Greenlight\Attribute\Test;
use Greenlight\Core\Test\TestMetadata;
use Greenlight\Expect\Expect;

final class TestMetadataCaptureWireTest
{
    #[Test]
    public function disabledOutputCaptureSurvivesWorkerTransport(): void
    {
        $payload = new TestMetadata(
            'App\QuietTest',
            'doesNotCapture',
            capture: false,
        )->toWire();

        $restored = TestMetadata::fromWire($payload);

        Expect::that($payload['capture'])
            ->because('worker metadata MUST preserve disabled output capture')
            ->toBeFalse()
            ->and($restored->capture)
            ->toBeFalse();
    }
}
