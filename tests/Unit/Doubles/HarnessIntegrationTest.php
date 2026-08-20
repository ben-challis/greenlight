<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Doubles;

use Greenlight\Attribute\Test;
use Greenlight\Core\Condition;
use Greenlight\Core\Wire\WireSerializable;
use Greenlight\Doubles\Doubles;
use Greenlight\Doubles\MockPlan;
use Greenlight\Expect\Expect;
use Greenlight\Expect\ExpectationExtension;
use Greenlight\Expect\ExpectationFailed;
use Greenlight\Harness\Disposable;
use Greenlight\Harness\HarnessRegistry;
use Greenlight\Harness\Scope;
use Greenlight\Harness\ScopeContainer;
use Greenlight\Harness\ServiceDefinition;
use Greenlight\Runner\Worker\EventSink;
use Greenlight\Tests\Fixture\Doubles\Calculator;

final class HarnessIntegrationTest
{
    #[Test]
    public function registeredAsAPerTestServiceItVerifiesAtScopeClose(): void
    {
        $registry = new HarnessRegistry([
            new ServiceDefinition(Doubles::class, Scope::PerTest, static fn(): Doubles => new Doubles()),
        ]);

        $definition = $registry->find(Doubles::class);

        Expect::that($definition)
            ->because('HarnessRegistry::find() MUST return the Doubles ServiceDefinition.')
            ->toBeInstanceOf(ServiceDefinition::class);

        $container = new ScopeContainer();
        $doubles = $container->get($definition);

        Expect::that($doubles)
            ->because('ScopeContainer::get() MUST return Doubles.')
            ->toBeInstanceOf(Doubles::class);

        $doubles->mock(Calculator::class, static function (MockPlan $plan): void {
            $plan->expects('add')->once();
        });

        $failures = $container->dispose();

        Expect::that($failures)->because('registered as a per test service it verifies at scope close')->toHaveCount(1);
        Expect::that($failures[0])->toBeInstanceOf(ExpectationFailed::class);

        $failure = $failures[0];
        Expect::that($failure->detail()->message)->toContain('add');
    }

    #[Test]
    public function everyGreenlightInterfaceCanBeDoubled(): void
    {
        $doubles = new Doubles();

        $condition = $doubles->stub(Condition::class);
        $disposable = $doubles->stub(Disposable::class);
        $extension = $doubles->stub(ExpectationExtension::class);
        $wire = $doubles->stub(WireSerializable::class);
        $events = $doubles->spy(EventSink::class);

        Expect::that($condition)->because('every greenlight interface can be doubled')->toBeInstanceOf(Condition::class);
        Expect::that($disposable)->toBeInstanceOf(Disposable::class);
        Expect::that($wire)->toBeInstanceOf(WireSerializable::class);
        Expect::that($extension)->toBeInstanceOf(ExpectationExtension::class);
        Expect::that($events)->toBeInstanceOf(EventSink::class);

        $doubles->dispose();
    }
}
