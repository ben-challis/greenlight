<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Doubles;

use Greenlight\Attribute\Test;
use Greenlight\Doubles\Doubles;
use Greenlight\Doubles\InvalidDoubleUsage;
use Greenlight\Expect\Expect;

final readonly class RepeatedCallContractTest
{
    public function __construct(private Doubles $doubles) {}

    #[Test]
    public function repeatedCallsKeepEachMethodsArgumentLimit(): void
    {
        $spy = $this->doubles->spy(RepeatedCallTarget::class);
        $spy->record();
        $spy->record('first');
        $spy->many('first', 'second');
        $spy->many();

        Expect::that(static fn(): mixed => new \ReflectionMethod($spy, 'record')->invokeArgs($spy, ['first', 'second']))
            ->toThrow(InvalidDoubleUsage::class, '/accepts at most 1 argument/');

        $spy->record('last');

        Expect::that($this->doubles->callsTo($spy, 'record'))->toBe([[], ['first'], ['last']]);
        Expect::that($this->doubles->callsTo($spy, 'many'))->toBe([['first', 'second'], []]);
    }

    #[Test]
    public function methodsWithTheSameNameKeepTheirOwnTypeContract(): void
    {
        $first = $this->doubles->spy(RepeatedCallTarget::class);
        $second = $this->doubles->spy(EmptyCallTarget::class);
        $first->record('first');
        $second->record();

        Expect::that(static fn(): mixed => new \ReflectionMethod($second, 'record')->invokeArgs($second, ['unexpected']))
            ->toThrow(InvalidDoubleUsage::class, '/accepts at most 0 arguments/');

        $first->record('last');

        Expect::that($this->doubles->callsTo($first, 'record'))->toBe([['first'], ['last']]);
        Expect::that($this->doubles->callsTo($second, 'record'))->toBe([[]]);
    }
}

interface RepeatedCallTarget
{
    public function record(string $value = 'default'): void;

    public function many(string ...$values): void;
}

interface EmptyCallTarget
{
    public function record(): void;
}
