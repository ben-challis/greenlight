<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Doubles;

use Greenlight\Attribute\Test;
use Greenlight\Condition\Condition;
use Greenlight\Doubles\Doubles;
use Greenlight\Doubles\MockPlan;
use Greenlight\Event\EventSink;
use Greenlight\Expect\Expect;
use Greenlight\Expect\ExpectationExtension;
use Greenlight\Expect\ExpectationFailed;
use Greenlight\Harness\Disposable;
use Greenlight\Harness\HarnessScopes;
use Greenlight\Harness\Scope;
use Greenlight\Harness\ServiceDefinition;
use Greenlight\Tests\Fixture\Doubles\Calculator;

final class HarnessIntegrationTest
{
    #[Test]
    public function registeredAsAPerTestServiceItVerifiesAtScopeClose(): void
    {
        $scopes = new HarnessScopes([
            new ServiceDefinition(Doubles::class, Scope::PerTest, static fn(): Doubles => new Doubles()),
        ]);
        $scopes->openTest();

        $doubles = $scopes->resolve(Doubles::class, self::class);

        Expect::value($doubles)
            ->because('HarnessScopes::resolve() MUST return Doubles.')
            ->toBeInstanceOf(Doubles::class);

        $doubles->mock(Calculator::class, static function (MockPlan $plan): void {
            $plan->expects('add')->once();
        });

        $failures = $scopes->closeTest();

        Expect::value($failures)->because('registered as a per test service it verifies at scope close')->toHaveCount(1);
        Expect::value($failures[0])->toBeInstanceOf(ExpectationFailed::class);

        $failure = $failures[0];
        Expect::value($failure->detail()->message)->toContain('add');
    }

    #[Test]
    public function publicHarnessInterfacesCanBeDoubled(): void
    {
        $doubles = new Doubles();

        $condition = $doubles->stub(Condition::class);
        $disposable = $doubles->stub(Disposable::class);
        $extension = $doubles->stub(ExpectationExtension::class);
        $events = $doubles->spy(EventSink::class);

        Expect::value($condition)->because('public harness interfaces can be doubled')->toBeInstanceOf(Condition::class);
        Expect::value($disposable)->toBeInstanceOf(Disposable::class);
        Expect::value($extension)->toBeInstanceOf(ExpectationExtension::class);
        Expect::value($events)->toBeInstanceOf(EventSink::class);

        $doubles->dispose();
    }
}
