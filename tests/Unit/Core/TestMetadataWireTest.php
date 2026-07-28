<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Core;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Core\Test\TestMetadata;
use Greenlight\Core\Wire\InvalidWirePayload;
use Greenlight\Expect\Expect;

final class TestMetadataWireTest
{
    #[Test]
    #[DataSet('backwardCompatibleFields')]
    public function missingBackwardCompatibleFieldsUseTheirDefaults(string $field, mixed $expected): void
    {
        $payload = new TestMetadata(
            'App\ExampleTest',
            'checksValue',
            noExpectations: true,
            skipUnlessArguments: ['configured'],
            dataSetProviderClass: 'App\Rows',
        )->toWire();
        unset($payload[$field]);

        Expect::that(TestMetadata::fromWire($payload)->toWire()[$field])
            ->because('missing backward compatible fields use their defaults')
            ->toBe($expected);
    }

    #[Test]
    #[DataSet('normalizedLegacyValues')]
    public function legacyWireValuesNormalizeToCurrentMetadata(string $field, mixed $legacy, mixed $expected): void
    {
        $payload = new TestMetadata('App\ExampleTest', 'checksValue')->toWire();
        $payload[$field] = $legacy;

        Expect::that(TestMetadata::fromWire($payload)->toWire()[$field])
            ->because('legacy wire values normalize to current metadata')
            ->toBe($expected);
    }

    #[Test]
    public function skipUnlessArgumentsMustUseAListShapeOnTheWire(): void
    {
        $payload = new TestMetadata('App\ExampleTest', 'checksValue')->toWire();
        $payload['skipUnlessArguments'] = ['named' => 'value'];

        Expect::that(static fn(): TestMetadata => TestMetadata::fromWire($payload))
            ->because('skip unless arguments must use a list shape on the wire')
            ->toThrow(
                InvalidWirePayload::class,
                message: 'Wire payload key "skipUnlessArguments" must be a list of scalars or nulls, got array.',
            );
    }

    #[Test]
    #[DataSet('invalidTimeouts')]
    public function invalidTimeoutsAreRejectedOnBothSides(float $seconds): void
    {
        Expect::that(
            static fn(): TestMetadata => new TestMetadata(
                'App\ExampleTest',
                'checksValue',
                timeoutSeconds: $seconds,
            ),
        )
            ->because('invalid timeouts are rejected by direct construction')
            ->toThrow(
                \InvalidArgumentException::class,
                message: 'Timeout seconds must be finite and greater than zero.',
            );

        $payload = new TestMetadata('App\ExampleTest', 'checksValue')->toWire();
        $payload['timeoutSeconds'] = $seconds;

        Expect::that(static fn(): TestMetadata => TestMetadata::fromWire($payload))
            ->because('invalid timeouts are rejected as wire protocol errors')
            ->toThrow(
                InvalidWirePayload::class,
                message: 'Wire payload key "timeoutSeconds" must be a finite float greater than zero or null, got float.',
            );
    }

    /**
     * @return iterable<string, array{non-empty-string, mixed}>
     */
    public static function backwardCompatibleFields(): iterable
    {
        yield 'data-set provider class' => ['dataSetProviderClass', null];
        yield 'skip-unless arguments' => ['skipUnlessArguments', []];
        yield 'no-expectations policy' => ['noExpectations', false];
    }

    /**
     * @return iterable<string, array{non-empty-string, mixed, mixed}>
     */
    public static function normalizedLegacyValues(): iterable
    {
        yield 'skip reason' => ['skipReason', '', null];
        yield 'skip condition' => ['skipUnlessCondition', '', null];
        yield 'retry throwable' => ['retryOnlyOn', '', null];
        yield 'data-set provider' => ['dataSetProvider', '', null];
        yield 'data-set provider class' => ['dataSetProviderClass', '', null];
        yield 'retry count' => ['retryTimes', 0, 1];
    }

    /**
     * @return iterable<string, array{float}>
     */
    public static function invalidTimeouts(): iterable
    {
        yield 'zero' => [0.0];
        yield 'negative' => [-0.5];
        yield 'positive infinity' => [\INF];
        yield 'not a number' => [\NAN];
    }
}
