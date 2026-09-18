<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Doubles;

use Greenlight\Attribute\Test;
use Greenlight\Doubles\Doubles;
use Greenlight\Doubles\InvalidDoubleUsage;
use Greenlight\Doubles\MockPlan;
use Greenlight\Expect\Expect;
use Greenlight\Expect\ExpectationFailed;
use Greenlight\Tests\Fixture\Doubles\CloneableRecorder;
use Greenlight\Tests\Fixture\Doubles\CloneProbe;
use Greenlight\Tests\Fixture\Doubles\FinalCloneProbe;
use Greenlight\Tests\Fixture\Doubles\MixedCaseMagicMethods;

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

        Expect::value(CloneProbe::$calls)
            ->because('a mock MUST NOT run the doubled clone method')
            ->toBe(0);
        Expect::value($this->doubles->callsTo($clone, '__clone'))
            ->because('the clone MUST use the same interaction state as the original double')
            ->toEqual([[]]);
    }

    #[Test]
    public function spiesRecordClone(): void
    {
        $double = $this->doubles->spy(CloneProbe::class);
        $clone = clone $double;

        Expect::value($this->doubles->callsTo($clone, '__clone'))
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

        Expect::value($first)->not()->toBe($original);
        Expect::value($second)->not()->toBe($first);
        Expect::value($this->doubles->callsTo($original, '__clone'))->toEqual([[], []]);
        Expect::value($this->doubles->callsTo($first, '__clone'))->toEqual([[], []]);
        Expect::value($this->doubles->callsTo($second, '__clone'))->toEqual([[], []]);
        Expect::value($this->doubles->callsTo($original, 'record'))->toEqual([['original'], ['first'], ['second']]);
        Expect::value($this->doubles->callsTo($first, 'record'))->toEqual([['original'], ['first'], ['second']]);
        Expect::value($this->doubles->callsTo($second, 'record'))->toEqual([['original'], ['first'], ['second']]);
    }

    #[Test]
    public function aCloneDoesNotResetThePlannedCloneCount(): void
    {
        $doubles = new Doubles();
        $original = $doubles->mock(CloneProbe::class, static function (MockPlan $plan): void {
            $plan->expects('__clone')->once();
        });
        $clone = clone $original;

        Expect::calling(static fn(): object => clone $clone)
            ->toThrow(ExpectationFailed::class, '/unexpected call to .*::__clone\(\)/');
        Expect::calling(static fn() => $doubles->dispose())
            ->toThrow(ExpectationFailed::class, '/unexpected call to .*::__clone\(\)/');
    }

    #[Test]
    public function anUnplannedCloneFailsImmediatelyAndAtDisposal(): void
    {
        $doubles = new Doubles();
        $double = $doubles->mock(CloneProbe::class);

        Expect::calling(static fn(): object => clone $double)
            ->toThrow(ExpectationFailed::class, '/unexpected call to .*::__clone\(\)/');
        Expect::value($doubles->callsTo($double, '__clone'))->toEqual([[]]);
        Expect::calling(static fn() => $doubles->dispose())
            ->toThrow(ExpectationFailed::class, '/unexpected call to .*::__clone\(\)/');
    }

    #[Test]
    public function aCloneCanThrowTheConfiguredException(): void
    {
        $failure = new \RuntimeException('Clone failed.');
        $double = $this->doubles->mock(CloneProbe::class, static function (MockPlan $plan) use ($failure): void {
            $plan->expects('__clone')->once()->andThrows($failure);
        });

        Expect::calling(static fn(): object => clone $double)
            ->toThrow(static function (\RuntimeException $actual) use ($failure): void {
                Expect::value($actual)->toBe($failure);
            });
        Expect::value($this->doubles->callsTo($double, '__clone'))->toEqual([[]]);
    }

    #[Test]
    public function mixedCaseUntypedCloneMethodsNeedNoConfiguredAnswer(): void
    {
        $double = $this->doubles->mock(MixedCaseMagicMethods::class, static function (MockPlan $plan): void {
            $plan->expects('__clone')->once();
        });
        $clone = clone $double;

        Expect::value($this->doubles->callsTo($double, '__clone'))->toEqual([[]]);
        Expect::value($this->doubles->callsTo($clone, '__CLONE'))->toEqual([[]]);
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

        Expect::value($originalReference->get())->toBeNull();
        $clone->record('after release');
        Expect::value($doubles->callsTo($clone, '__clone'))->toEqual([[]]);
        Expect::value($doubles->callsTo($clone, 'record'))->toEqual([['after release']]);

        $doubles->dispose();
        unset($clone);
        \gc_collect_cycles();

        Expect::value($cloneReference->get())->toBeNull();
    }

    #[Test]
    public function aCloneCanBeCollectedWhileItsOriginalKeepsTheCallHistory(): void
    {
        $original = $this->doubles->spy(CloneProbe::class);
        $clone = clone $original;
        $reference = \WeakReference::create($clone);

        unset($clone);
        \gc_collect_cycles();

        Expect::value($reference->get())->toBeNull();
        Expect::value($this->doubles->callsTo($original, '__clone'))->toEqual([[]]);
    }

    #[Test]
    public function stubsRejectClone(): void
    {
        $double = $this->doubles->stub(CloneProbe::class);

        Expect::calling(static fn(): object => clone $double)
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

        Expect::value(FinalCloneProbe::$calls)
            ->because('a class double cannot intercept a final clone method')
            ->toBe(1);
        Expect::value($clone)->toBeInstanceOf(FinalCloneProbe::class);
    }
}
