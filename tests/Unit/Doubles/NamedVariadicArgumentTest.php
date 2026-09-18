<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Doubles;

use Greenlight\Attribute\Test;
use Greenlight\Doubles\Doubles;
use Greenlight\Doubles\MockPlan;
use Greenlight\Expect\Expect;
use Greenlight\Tests\Fixture\Doubles\NamedVariadicContract;

final readonly class NamedVariadicArgumentTest
{
    public function __construct(private Doubles $doubles) {}

    #[Test]
    public function spiesRecordPositionalAndNamedVariadicValues(): void
    {
        $spy = $this->doubles->spy(NamedVariadicContract::class);
        $spy->record('one', second: 'two');

        Expect::value($this->doubles->callsTo($spy, 'record'))->toBe([['one', 'two']]);
    }

    #[Test]
    public function callbacksKeepNamesWhilePlansAndCaptorsUsePositions(): void
    {
        $captor = null;
        $double = $this->doubles->mock(NamedVariadicContract::class, static function (MockPlan $plan) use (&$captor): void {
            $captor = $plan->expects('record')->with('one', 'two')->once()
                ->andReturnsUsing(static function (string ...$values): void {
                    Expect::value($values)->toBe(['one', 'second' => 'two']);
                })
                ->captureArgument(1);
        });
        $double->record('one', second: 'two');

        Expect::value($captor?->values())->toBe(['two']);
    }

    #[Test]
    public function callbacksCanChangeFixedAndNamedVariadicReferences(): void
    {
        $double = $this->doubles->mock(NamedVariadicContract::class, static function (MockPlan $plan): void {
            $plan->expects('mutate')->once()->andReturnsUsing(static function (string &$prefix, string &...$values): void {
                $prefix .= ' changed';
                Expect::value(\array_keys($values))->toBe(['tail']);
                foreach ($values as &$value) {
                    $value .= ' changed';
                }
            });
        });
        $prefix = 'prefix';
        $tail = 'tail';
        $double->mutate($prefix, tail: $tail);

        Expect::value($prefix)->toBe('prefix changed');
        Expect::value($tail)->toBe('tail changed');
    }

    #[Test]
    public function namedVariadicValuesDoNotSupplyOmittedFixedArguments(): void
    {
        $double = $this->doubles->mock(NamedVariadicContract::class, static function (MockPlan $plan): void {
            $plan->expects('withPrefix')->once()->andReturnsUsing(static function (string $prefix = 'callback-default', string ...$values): void {
                Expect::value($prefix)->toBe('callback-default');
                Expect::value($values)->toBe(['tail' => 'one']);
                Expect::value(\func_num_args())->toBe(0);
            });
        });

        $double->withPrefix(tail: 'one');
        Expect::value($this->doubles->callsTo($double, 'withPrefix'))->toBe([['one']]);
    }
}
