<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Core;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Core\Test\DataProvider;
use Greenlight\Core\Test\ExecutionPolicy;
use Greenlight\Core\Test\RetryPolicy;
use Greenlight\Core\Test\SchedulingPolicy;
use Greenlight\Core\Test\SkipPolicy;
use Greenlight\Core\Test\TestDefinition;
use Greenlight\Core\Wire\InvalidWirePayload;
use Greenlight\Expect\Expect;
use Greenlight\Tests\Support\JsonWire;

final class TestDefinitionTest
{
    #[Test]
    public function composedDefinitionSurvivesTheWire(): void
    {
        $definition = new TestDefinition(
            'App\FooTest',
            'bar',
            ['slow', 'io'],
            new SkipPolicy(condition: 'App\OnPosix', arguments: ['redis', 42, 1.5, true, null]),
            new RetryPolicy(3, \RuntimeException::class),
            new DataProvider('currencies', 'App\SharedDataSets'),
            new ExecutionPolicy(5.5, capture: false, noExpectations: true),
            new SchedulingPolicy(true, ['postgres', 'redis', 'postgres'], true),
        );

        $restored = TestDefinition::fromWire(JsonWire::roundTrip($definition->toWire()));

        Expect::that($restored->toWire())
            ->because('the composed test definition MUST survive the wire')
            ->toBe($definition->toWire());
        Expect::that($restored->scheduling->resources)
            ->because('the scheduling policy MUST remove duplicate resources')
            ->toBe(['postgres', 'redis']);
    }

    #[Test]
    public function defaultsCreateEmptyPolicies(): void
    {
        $definition = new TestDefinition('App\FooTest', 'bar');

        Expect::that($definition->groups)->toBe([]);
        Expect::that($definition->skip->toWire())->toBe(new SkipPolicy()->toWire());
        Expect::that($definition->retry->toWire())->toBe(new RetryPolicy()->toWire());
        Expect::that($definition->dataProvider->toWire())->toBe(new DataProvider()->toWire());
        Expect::that($definition->execution->toWire())->toBe(new ExecutionPolicy()->toWire());
        Expect::that($definition->scheduling->toWire())->toBe(new SchedulingPolicy()->toWire());
    }

    #[Test]
    public function rejectsEmptyGroupsOnBothSides(): void
    {
        Expect::that(static fn(): TestDefinition => new TestDefinition('App\FooTest', 'bar', ['ok', '']))
            ->because('a direct definition MUST reject an empty group')
            ->toThrow(\InvalidArgumentException::class, message: 'Group names cannot be empty.');

        $payload = new TestDefinition('App\FooTest', 'bar', ['ok'])->toWire();
        $payload['groups'] = ['ok', ''];

        Expect::that(static fn(): TestDefinition => TestDefinition::fromWire($payload))
            ->because('a wire definition MUST reject an empty group')
            ->toThrow(InvalidWirePayload::class);
    }

    #[Test]
    public function retainsAZeroGroupAcrossTheWire(): void
    {
        $definition = new TestDefinition('App\ExampleTest', 'example', groups: ['0']);
        $decoded = TestDefinition::fromWire(JsonWire::roundTrip($definition->toWire()));

        Expect::that($decoded->groups)
            ->because('the group name MUST survive the wire')
            ->toBe(['0']);
    }

    #[Test]
    #[DataSet('invalidIdentities')]
    public function rejectsInvalidDeclarationIdentity(string $class, string $method, string $message): void
    {
        Expect::that(static fn(): TestDefinition => new TestDefinition($class, $method))
            ->because('a test definition MUST identify a class and method')
            ->toThrow(\InvalidArgumentException::class, message: $message);
    }

    /**
     * @return iterable<string, array{string, string, non-empty-string}>
     */
    public static function invalidIdentities(): iterable
    {
        yield 'empty class' => ['', 'bar', 'Test definition class must not be empty.'];
        yield 'empty method' => ['App\FooTest', '', 'Test definition method must not be empty.'];
    }
}
