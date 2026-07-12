<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Core;

use Greenlight\Attribute\Test;
use Greenlight\Core\Test\TestMetadata;
use Greenlight\Core\Wire\InvalidWirePayload;
use Greenlight\Expect\Expect;
use Greenlight\Tests\Support\JsonWire;

final class TestMetadataTest
{
    #[Test]
    public function survivesTheWireFullyPopulated(): void
    {
        $metadata = new TestMetadata(
            'App\FooTest',
            'bar',
            ['slow', 'io'],
            null,
            'App\OnPosix',
            3,
            \RuntimeException::class,
            5.5,
            true,
            'currencies',
        );

        $restored = TestMetadata::fromWire(JsonWire::roundTrip($metadata->toWire()));

        Expect::that($restored->class)->toBe('App\FooTest');
        Expect::that($restored->method)->toBe('bar');
        Expect::that($restored->groups)->toBe(['slow', 'io']);
        Expect::that($restored->skipReason)->toBe(null);
        Expect::that($restored->skipUnlessCondition)->toBe('App\OnPosix');
        Expect::that($restored->skipUnlessArguments)->toBe([]);
        Expect::that($restored->retryTimes)->toBe(3);
        Expect::that($restored->retryOnlyOn)->toBe(\RuntimeException::class);
        Expect::that($restored->timeoutSeconds)->toBe(5.5);
        Expect::that($restored->isolated)->toBe(true);
        Expect::that($restored->dataSetProvider)->toBe('currencies');
    }

    #[Test]
    public function survivesTheWireWithDefaults(): void
    {
        $metadata = new TestMetadata('App\FooTest', 'bar');
        $restored = TestMetadata::fromWire(JsonWire::roundTrip($metadata->toWire()));

        Expect::that($restored->groups)->toBe([]);
        Expect::that($restored->retryTimes)->toBe(null);
        Expect::that($restored->timeoutSeconds)->toBe(null);
        Expect::that($restored->isolated)->toBe(false);
    }

    #[Test]
    public function skipUnlessArgumentsSurviveTheWire(): void
    {
        $metadata = new TestMetadata(
            'App\FooTest',
            'bar',
            skipUnlessCondition: 'App\OnPosix',
            skipUnlessArguments: ['redis', 42, 1.5, true, null],
        );

        $restored = TestMetadata::fromWire(JsonWire::roundTrip($metadata->toWire()));

        Expect::that($restored->skipUnlessCondition)->toBe('App\OnPosix');
        Expect::that($restored->skipUnlessArguments)->toBe(['redis', 42, 1.5, true, null]);
    }

    #[Test]
    public function rejectsNonScalarSkipUnlessArgumentsOnBothSides(): void
    {
        Expect::that(
            static fn(): TestMetadata => new TestMetadata('App\FooTest', 'bar', skipUnlessArguments: [['nested']]),
        )->toThrow(\InvalidArgumentException::class);

        $payload = new TestMetadata('App\FooTest', 'bar')->toWire();
        $payload['skipUnlessArguments'] = [['nested']];

        Expect::that(
            static fn(): TestMetadata => TestMetadata::fromWire($payload),
        )->toThrow(InvalidWirePayload::class);
    }

    #[Test]
    public function rejectsEmptyGroupNamesOnBothSides(): void
    {
        Expect::that(
            static fn(): TestMetadata => new TestMetadata('App\FooTest', 'bar', ['ok', '']),
        )->toThrow(\InvalidArgumentException::class);

        $payload = new TestMetadata('App\FooTest', 'bar', ['ok'])->toWire();
        $payload['groups'] = ['ok', ''];

        Expect::that(
            static fn(): TestMetadata => TestMetadata::fromWire($payload),
        )->toThrow(InvalidWirePayload::class);
    }

    #[Test]
    public function missingOptionalKeysFailLoudly(): void
    {
        $payload = new TestMetadata('App\FooTest', 'bar')->toWire();
        unset($payload['retryTimes']);

        Expect::that(
            static fn(): TestMetadata => TestMetadata::fromWire($payload),
        )->toThrow(InvalidWirePayload::class);

        $payload = new TestMetadata('App\FooTest', 'bar')->toWire();
        unset($payload['timeoutSeconds']);

        Expect::that(
            static fn(): TestMetadata => TestMetadata::fromWire($payload),
        )->toThrow(InvalidWirePayload::class);
    }
}
