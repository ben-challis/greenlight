<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Doubles;

use Greenlight\Attribute\Test;
use Greenlight\Doubles\Doubles;
use Greenlight\Doubles\MockPlan;
use Greenlight\Tests\Fixture\Doubles\OptionalArguments;

use function Greenlight\expect;

final readonly class DefaultArgumentMatchingTest
{
    public function __construct(private Doubles $doubles) {}

    #[Test]
    public function aPlannedDefaultMatchesACallThatOmitsIt(): void
    {
        $double = $this->doubles->mock(OptionalArguments::class, static function (MockPlan $plan): void {
            $plan->expects('find')->with('a', 2)->once()->andReturns('found');
        });

        expect($double->find('a'))
            ->because('an omitted argument MUST match its declared default')
            ->toBe('found');
    }

    #[Test]
    public function anOmittedPlanArgumentMatchesAnExplicitDefault(): void
    {
        $double = $this->doubles->mock(OptionalArguments::class, static function (MockPlan $plan): void {
            $plan->expects('find')->with('a')->times(2)->andReturns('found');
        });

        expect([$double->find('a', 2), $double->find('a', 2, 'none')])
            ->because('a plan that omits optional arguments MUST use their declared defaults')
            ->toBe(['found', 'found']);
    }

    #[Test]
    public function namedArgumentsRecordTheSameListAsPositionalArguments(): void
    {
        $double = $this->doubles->mock(OptionalArguments::class, static function (MockPlan $plan): void {
            $plan->expects('find')->times(3)->andReturns('found');
        });
        $double->find('a');
        $double->find('a', tag: 'none');
        $double->find('a', 2, 'none');

        expect($this->doubles->callsTo($double, 'find'))
            ->because('each call MUST record every declared default')
            ->toBe([['a', 2, 'none'], ['a', 2, 'none'], ['a', 2, 'none']]);
    }

    #[Test]
    public function aNamedArgumentMatchesAPlanThatOmitsTheSkippedDefault(): void
    {
        $double = $this->doubles->mock(OptionalArguments::class, static function (MockPlan $plan): void {
            $plan->expects('find')->with('a', 2, 'tag')->once()->andReturns('tagged');
        });

        expect($double->find('a', tag: 'tag'))
            ->because('a named argument MUST NOT change the recorded argument positions')
            ->toBe('tagged');
    }

    #[Test]
    public function objectDefaultsUseDeepEquality(): void
    {
        $double = $this->doubles->mock(OptionalArguments::class, static function (MockPlan $plan): void {
            $plan->expects('since')->with('key')->once()->andReturns('found');
        });

        expect($double->since('key'))
            ->because('each call constructs a new default object')
            ->toBe('found');
    }

    #[Test]
    public function notANumberDefaultsMatchTheirOmittedArguments(): void
    {
        $double = $this->doubles->mock(OptionalArguments::class, static function (MockPlan $plan): void {
            $plan->expects('measure')->with('key')->once()->andReturns('measured');
        });

        expect($double->measure('key'))
            ->because('a NAN default MUST match the same omitted argument')
            ->toBe('measured');
    }
}
