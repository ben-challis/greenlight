<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Doubles;

use Greenlight\Attribute\Test;
use Greenlight\Doubles\Doubles;
use Greenlight\Doubles\InvalidDoubleUsage;
use Greenlight\Doubles\MockPlan;
use Greenlight\Expect\ExpectationFailed;
use Greenlight\Tests\Fixture\Doubles\CloneableRecorder;
use Greenlight\Tests\Fixture\Doubles\CloneProbe;
use Greenlight\Tests\Fixture\Doubles\FinalCloneProbe;
use Greenlight\Tests\Fixture\Doubles\MixedCaseMagicMethods;

use function Greenlight\expect;

final readonly class CloneInterceptionTest
{
    public function __construct(private Doubles $doubles) {}

    #[Test]
    public function mocksInterceptCloneAndRegisterTheClone(): void
    {
        CloneProbe::$calls = 0;
        $double = $this->doubles->mock(CloneProbe::class, static function (MockPlan $plan): void {
            $plan->expects('__clone')->once();
        });

        $clone = clone $double;

        expect(CloneProbe::$calls)
            ->because('a mock MUST NOT run the doubled clone method')
            ->toBe(0);
        expect($this->doubles->callsTo($clone, '__clone'))
            ->because('the clone MUST use the same interaction state as the original double')
            ->toEqual([[]]);
    }

    #[Test]
    public function spiesRecordClone(): void
    {
        $double = $this->doubles->spy(CloneProbe::class);
        $clone = clone $double;

        expect($this->doubles->callsTo($clone, '__clone'))
            ->because('a spy MUST record the clone interaction')
            ->toEqual([[]]);
    }

    #[Test]
    public function repeatedClonesShareExpectationsAndCallHistoryWithTheOriginal(): void
    {
        $original = $this->doubles->mock(CloneableRecorder::class, static function (MockPlan $plan): void {
            $plan->expects('__clone')->times(2);
            $plan->expects('record')->times(3);
        });

        $first = clone $original;
        $second = clone $first;
        $original->record('original');
        $first->record('first');
        $second->record('second');

        expect($first)->not()->toBe($original);
        expect($second)->not()->toBe($first);
        expect($this->doubles->callsTo($original, '__clone'))->toEqual([[], []]);
        expect($this->doubles->callsTo($first, '__clone'))->toEqual([[], []]);
        expect($this->doubles->callsTo($second, '__clone'))->toEqual([[], []]);
        expect($this->doubles->callsTo($original, 'record'))->toEqual([['original'], ['first'], ['second']]);
        expect($this->doubles->callsTo($first, 'record'))->toEqual([['original'], ['first'], ['second']]);
        expect($this->doubles->callsTo($second, 'record'))->toEqual([['original'], ['first'], ['second']]);
    }

    #[Test]
    public function aCloneDoesNotResetThePlannedCloneCount(): void
    {
        $doubles = new Doubles();
        $original = $doubles->mock(CloneProbe::class, static function (MockPlan $plan): void {
            $plan->expects('__clone')->once();
        });
        $clone = clone $original;

        expect()->calling(static fn(): object => clone $clone)
            ->toThrow(ExpectationFailed::class, '/unexpected call to .*::__clone\(\)/');
        expect()->calling(static fn() => $doubles->dispose())
            ->toThrow(ExpectationFailed::class, '/unexpected call to .*::__clone\(\)/');
    }

    #[Test]
    public function anUnplannedCloneFailsImmediatelyAndAtDisposal(): void
    {
        $doubles = new Doubles();
        $double = $doubles->mock(CloneProbe::class);

        expect()->calling(static fn(): object => clone $double)
            ->toThrow(ExpectationFailed::class, '/unexpected call to .*::__clone\(\)/');
        expect($doubles->callsTo($double, '__clone'))->toEqual([[]]);
        expect()->calling(static fn() => $doubles->dispose())
            ->toThrow(ExpectationFailed::class, '/unexpected call to .*::__clone\(\)/');
    }

    #[Test]
    public function aCloneCanThrowTheConfiguredException(): void
    {
        $failure = new \RuntimeException('Clone failed.');
        $double = $this->doubles->mock(CloneProbe::class, static function (MockPlan $plan) use ($failure): void {
            $plan->expects('__clone')->once()->andThrows($failure);
        });

        expect()->calling(static fn(): object => clone $double)
            ->toThrow(static function (\RuntimeException $actual) use ($failure): void {
                expect($actual)->toBe($failure);
            });
        expect($this->doubles->callsTo($double, '__clone'))->toEqual([[]]);
    }

    #[Test]
    public function mixedCaseUntypedCloneMethodsNeedNoConfiguredAnswer(): void
    {
        $double = $this->doubles->mock(MixedCaseMagicMethods::class, static function (MockPlan $plan): void {
            $plan->expects('__clone')->once();
        });
        $clone = clone $double;

        expect($this->doubles->callsTo($double, '__clone'))->toEqual([[]]);
        expect($this->doubles->callsTo($clone, '__CLONE'))->toEqual([[]]);
    }

    #[Test]
    public function theOriginalCanBeCollectedWhileItsCloneKeepsTheCallHistory(): void
    {
        $doubles = new Doubles();
        $original = $doubles->spy(CloneableRecorder::class);
        $clone = clone $original;
        $originalReference = \WeakReference::create($original);
        $cloneReference = \WeakReference::create($clone);

        unset($original);
        \gc_collect_cycles();

        expect($originalReference->get())->toBeNull();
        $clone->record('after release');
        expect($doubles->callsTo($clone, '__clone'))->toEqual([[]]);
        expect($doubles->callsTo($clone, 'record'))->toEqual([['after release']]);

        $doubles->dispose();
        unset($clone);
        \gc_collect_cycles();

        expect($cloneReference->get())->toBeNull();
    }

    #[Test]
    public function aCloneCanBeCollectedWhileItsOriginalKeepsTheCallHistory(): void
    {
        $original = $this->doubles->spy(CloneProbe::class);
        $clone = clone $original;
        $reference = \WeakReference::create($clone);

        unset($clone);
        \gc_collect_cycles();

        expect($reference->get())->toBeNull();
        expect($this->doubles->callsTo($original, '__clone'))->toEqual([[]]);
    }

    #[Test]
    public function stubsRejectClone(): void
    {
        $double = $this->doubles->stub(CloneProbe::class);

        expect()->calling(static fn(): object => clone $double)
            ->because('a stub MUST reject the clone interaction')
            ->toThrow(
                InvalidDoubleUsage::class,
                message: 'Code called "__clone()" on the stub of "' . CloneProbe::class . '". '
                    . 'Stubs only satisfy a type. Use mock() with explicit expectations for interactions.',
            );
    }

    #[Test]
    public function finalCloneMethodsKeepTheirImplementation(): void
    {
        FinalCloneProbe::$calls = 0;
        $double = $this->doubles->stub(FinalCloneProbe::class);

        $clone = clone $double;

        expect(FinalCloneProbe::$calls)
            ->because('a class double cannot intercept a final clone method')
            ->toBe(1);
        expect($clone)->toBeInstanceOf(FinalCloneProbe::class);
    }
}
