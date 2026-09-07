<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Doubles;

use Greenlight\Attribute\Test;
use Greenlight\Doubles\Doubles;
use Greenlight\Doubles\MockPlan;
use Greenlight\Expect\Expect;
use Greenlight\Tests\Fixture\Doubles\ResultParameterCollision;
use Greenlight\Tests\Fixture\Doubles\VariadicReference;
use Greenlight\Tests\Fixture\Doubles\Wide;

final readonly class ReferenceValueIsolationTest
{
    public function __construct(private Doubles $doubles) {}

    #[Test]
    public function recordedCallsKeepReferenceValuesFromEachCall(): void
    {
        $spy = $this->doubles->spy(Wide::class);
        $items = ['first'];
        $spy->byReference($items);
        $items = ['second'];
        $spy->byReference($items);
        $items = ['later'];

        Expect::that($this->doubles->callsTo($spy, 'byReference'))->toBe([[['first']], [['second']]]);
        Expect::that($items)->toBe(['later']);
    }

    #[Test]
    public function recordedVariadicReferencesKeepTheirValuesBeforeTheCallback(): void
    {
        $mock = $this->doubles->mock(VariadicReference::class, static function (MockPlan $plan): void {
            $plan->expects('mutate')->andReturnsUsing(static function (string &...$values): void {
                foreach ($values as &$value) {
                    $value .= ' changed';
                }
            });
        });
        $first = 'first';
        $second = 'second';

        $mock->mutate($first, second: $second);

        Expect::that($this->doubles->callsTo($mock, 'mutate'))->toBe([['first', 'second']]);
        Expect::that($first)->toBe('first changed');
        Expect::that($second)->toBe('second changed');
    }

    #[Test]
    public function recordedReferenceValuesPreserveObjectIdentity(): void
    {
        $spy = $this->doubles->spy(Wide::class);
        $object = new \stdClass();
        $items = [$object];
        $spy->byReference($items);
        $items = [];

        Expect::that($this->doubles->callsTo($spy, 'byReference'))->toBe([[[$object]]]);
        Expect::that($items)->toBe([]);
    }

    #[Test]
    public function returnValuesDoNotOverwriteReferenceParametersWithGeneratedNames(): void
    {
        $mock = $this->doubles->mock(ResultParameterCollision::class, static function (MockPlan $plan): void {
            $plan->expects('result')->andReturns(42);
        });
        $first = 'first';
        $second = 'second';

        Expect::that($mock->result($first, $second))->toBe(42);
        Expect::that($first)->toBe('first');
        Expect::that($second)->toBe('second');
    }

    #[Test]
    public function referenceReturnsStaySeparateFromReferenceParameters(): void
    {
        $mock = $this->doubles->mock(ResultParameterCollision::class, static function (MockPlan $plan): void {
            $plan->expects('reference')->andReturns('answer');
        });
        $input = 'input';
        $result = &$mock->reference($input);
        $result = 'changed result';

        Expect::that($input)->toBe('input');
        Expect::that($result)->toBe('changed result');
    }
}
