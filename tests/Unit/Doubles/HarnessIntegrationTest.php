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
use Greenlight\Expect\FailureSink;
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

        if (!$definition instanceof ServiceDefinition) {
            throw new \RuntimeException('The Doubles service definition was not found.');
        }

        $container = new ScopeContainer();
        $doubles = $container->get($definition);

        if (!$doubles instanceof Doubles) {
            throw new \RuntimeException('The container resolved the wrong type.');
        }

        $doubles->mock(Calculator::class, static function (MockPlan $plan): void {
            $plan->expects('add')->once();
        });

        $failures = $container->dispose();

        $expect = new Expect();
        $expect->that($failures)->toHaveCount(1)
            ->and($failures[0])->toBeInstanceOf(ExpectationFailed::class);

        $failure = $failures[0];

        if ($failure instanceof ExpectationFailed) {
            $expect->that($failure->detail()->message)->toContain('add');
        }
    }

    #[Test]
    public function everyGreenlightInterfaceCanBeDoubled(): void
    {
        $doubles = new Doubles();

        $condition = $doubles->stub(Condition::class);
        $disposable = $doubles->stub(Disposable::class);
        $sink = $doubles->spy(FailureSink::class);
        $extension = $doubles->stub(ExpectationExtension::class);
        $wire = $doubles->stub(WireSerializable::class);
        $events = $doubles->stub(EventSink::class);

        $disposable->dispose();

        new Expect()->that($condition->isSatisfied())->toBeFalse()
            ->and($wire->toWire())->toBe([])
            ->and($sink)->toBeInstanceOf(FailureSink::class)
            ->and($extension)->toBeInstanceOf(ExpectationExtension::class)
            ->and($events)->toBeInstanceOf(EventSink::class);

        $doubles->dispose();
    }
}
