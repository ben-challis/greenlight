<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Core;

use Greenlight\Attribute\Test;
use Greenlight\Core\Test\TestMetadata;
use Greenlight\Core\Wire\InvalidWirePayload;
use Greenlight\Tests\Support\Check;

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

        $restored = TestMetadata::fromWire(Check::jsonRoundTrip($metadata->toWire()));

        Check::same('App\FooTest', $restored->class, 'class');
        Check::same('bar', $restored->method, 'method');
        Check::same(['slow', 'io'], $restored->groups, 'groups');
        Check::same(null, $restored->skipReason, 'skip reason');
        Check::same('App\OnPosix', $restored->skipUnlessCondition, 'skip-unless condition');
        Check::same(3, $restored->retryTimes, 'retry times');
        Check::same(\RuntimeException::class, $restored->retryOnlyOn, 'retry only-on');
        Check::same(5.5, $restored->timeoutSeconds, 'timeout');
        Check::same(true, $restored->isolated, 'isolated');
        Check::same('currencies', $restored->dataSetProvider, 'data-set provider');
    }

    #[Test]
    public function survivesTheWireWithDefaults(): void
    {
        $metadata = new TestMetadata('App\FooTest', 'bar');
        $restored = TestMetadata::fromWire(Check::jsonRoundTrip($metadata->toWire()));

        Check::same([], $restored->groups, 'groups default');
        Check::same(null, $restored->retryTimes, 'retry default');
        Check::same(null, $restored->timeoutSeconds, 'timeout default');
        Check::same(false, $restored->isolated, 'isolated default');
    }

    #[Test]
    public function rejectsEmptyGroupNamesOnBothSides(): void
    {
        Check::throws(
            static fn(): TestMetadata => new TestMetadata('App\FooTest', 'bar', ['ok', '']),
            \InvalidArgumentException::class,
            'constructor with empty group',
        );

        $payload = new TestMetadata('App\FooTest', 'bar', ['ok'])->toWire();
        $payload['groups'] = ['ok', ''];

        Check::throws(
            static fn(): TestMetadata => TestMetadata::fromWire($payload),
            InvalidWirePayload::class,
            'wire payload with empty group',
        );
    }

    #[Test]
    public function missingOptionalKeysFailLoudly(): void
    {
        $payload = new TestMetadata('App\FooTest', 'bar')->toWire();
        unset($payload['retryTimes']);

        Check::throws(
            static fn(): TestMetadata => TestMetadata::fromWire($payload),
            InvalidWirePayload::class,
            'payload missing retryTimes',
        );

        $payload = new TestMetadata('App\FooTest', 'bar')->toWire();
        unset($payload['timeoutSeconds']);

        Check::throws(
            static fn(): TestMetadata => TestMetadata::fromWire($payload),
            InvalidWirePayload::class,
            'payload missing timeoutSeconds',
        );
    }
}
