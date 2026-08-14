<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Core;

use Greenlight\Attribute\DataSet;
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
            resources: ['postgres', 'redis', 'postgres'],
            dataSetProviderClass: 'App\SharedDataSets',
        );

        $restored = TestMetadata::fromWire(JsonWire::roundTrip($metadata->toWire()));

        Expect::that($restored->class)->because('survives the wire fully populated')->toBe('App\FooTest');
        Expect::that($restored->method)->because('survives the wire fully populated')->toBe('bar');
        Expect::that($restored->groups)->because('survives the wire fully populated')->toBe(['slow', 'io']);
        Expect::that($restored->skipReason)->because('survives the wire fully populated')->toBe(null);
        Expect::that($restored->skipUnlessCondition)->because('survives the wire fully populated')->toBe('App\OnPosix');
        Expect::that($restored->skipUnlessArguments)->because('survives the wire fully populated')->toBe([]);
        Expect::that($restored->retryTimes)->because('survives the wire fully populated')->toBe(3);
        Expect::that($restored->retryOnlyOn)->because('survives the wire fully populated')->toBe(\RuntimeException::class);
        Expect::that($restored->timeoutSeconds)->because('survives the wire fully populated')->toBe(5.5);
        Expect::that($restored->isolated)->because('survives the wire fully populated')->toBe(true);
        Expect::that($restored->dataSetProvider)->because('survives the wire fully populated')->toBe('currencies');
        Expect::that($restored->dataSetProviderClass)->because('survives the wire fully populated')->toBe('App\SharedDataSets');
        Expect::that($restored->resources)->because('survives the wire fully populated')->toBe(['postgres', 'redis']);
    }

    #[Test]
    public function survivesTheWireWithDefaults(): void
    {
        $metadata = new TestMetadata('App\FooTest', 'bar');
        $restored = TestMetadata::fromWire(JsonWire::roundTrip($metadata->toWire()));

        Expect::that($restored->groups)->because('survives the wire with defaults')->toBe([]);
        Expect::that($restored->retryTimes)->because('survives the wire with defaults')->toBe(null);
        Expect::that($restored->timeoutSeconds)->because('survives the wire with defaults')->toBe(null);
        Expect::that($restored->isolated)->because('survives the wire with defaults')->toBe(false);
        Expect::that($restored->dataSetProviderClass)->because('survives the wire with defaults')->toBe(null);
        Expect::that($restored->resources)->because('survives the wire with defaults')->toBe([]);
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

        Expect::that($restored->skipUnlessCondition)->because('skip unless arguments survive the wire')->toBe('App\OnPosix');
        Expect::that($restored->skipUnlessArguments)->because('skip unless arguments survive the wire')->toBe(['redis', 42, 1.5, true, null]);
    }

    #[Test]
    public function rejectsNonScalarSkipUnlessArgumentsOnBothSides(): void
    {
        Expect::that(
            static fn(): TestMetadata => new TestMetadata('App\FooTest', 'bar', skipUnlessArguments: [['nested']]),
        )->because('rejects non scalar skip unless arguments on both sides')->toThrow(\InvalidArgumentException::class);

        $payload = new TestMetadata('App\FooTest', 'bar')->toWire();
        $payload['skipUnlessArguments'] = [['nested']];

        Expect::that(
            static fn(): TestMetadata => TestMetadata::fromWire($payload),
        )->because('rejects non scalar skip unless arguments on both sides')->toThrow(InvalidWirePayload::class);
    }

    #[Test]
    public function rejectsEmptyGroupNamesOnBothSides(): void
    {
        Expect::that(
            static fn(): TestMetadata => new TestMetadata('App\FooTest', 'bar', ['ok', '']),
        )->because('rejects empty group names on both sides')->toThrow(\InvalidArgumentException::class);

        $payload = new TestMetadata('App\FooTest', 'bar', ['ok'])->toWire();
        $payload['groups'] = ['ok', ''];

        Expect::that(
            static fn(): TestMetadata => TestMetadata::fromWire($payload),
        )->because('rejects empty group names on both sides')->toThrow(InvalidWirePayload::class);
    }

    #[Test]
    public function missingResourceWireKeyUsesTheBackwardCompatibleDefault(): void
    {
        $payload = new TestMetadata('App\FooTest', 'bar', resources: ['postgres'])->toWire();
        unset($payload['resources']);

        Expect::that(TestMetadata::fromWire($payload)->resources)->because('missing resource wire key uses the backward compatible default')->toBe([]);
    }

    #[Test]
    public function rejectsInvalidResourceNamesOnBothSides(): void
    {
        Expect::that(
            static fn(): TestMetadata => new TestMetadata('App\FooTest', 'bar', resources: ['Postgres']),
        )->because('rejects invalid resource names on both sides')->toThrow(\InvalidArgumentException::class);

        $payload = new TestMetadata('App\FooTest', 'bar')->toWire();
        $payload['resources'] = ['Postgres'];

        Expect::that(static fn(): TestMetadata => TestMetadata::fromWire($payload))->because('rejects invalid resource names on both sides')
            ->toThrow(InvalidWirePayload::class);
    }

    #[Test]
    public function missingOptionalKeysFailLoudly(): void
    {
        $payload = new TestMetadata('App\FooTest', 'bar')->toWire();
        unset($payload['retryTimes']);

        Expect::that(
            static fn(): TestMetadata => TestMetadata::fromWire($payload),
        )->because('missing optional keys cause an error')->toThrow(InvalidWirePayload::class);

        $payload = new TestMetadata('App\FooTest', 'bar')->toWire();
        unset($payload['timeoutSeconds']);

        Expect::that(
            static fn(): TestMetadata => TestMetadata::fromWire($payload),
        )->because('missing optional keys cause an error')->toThrow(InvalidWirePayload::class);
    }

    #[Test]
    #[DataSet('invalidIdentifiers')]
    public function rejectsInvalidIdentifiers(string $class, string $method, string $message): void
    {
        Expect::that(static fn(): TestMetadata => new TestMetadata($class, $method))
            ->because('test metadata MUST identify a class and method')
            ->toThrow(\InvalidArgumentException::class, message: $message);
    }

    /**
     * @return iterable<string, array{string, string, non-empty-string}>
     */
    public static function invalidIdentifiers(): iterable
    {
        yield 'empty class' => ['', 'bar', 'Test metadata class must not be empty.'];
        yield 'empty method' => ['App\FooTest', '', 'Test metadata method must not be empty.'];
    }
}
