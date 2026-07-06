<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Doubles;

use Greenlight\Attribute\Test;
use Greenlight\Doubles\Doubles;
use Greenlight\Doubles\DoublesError;
use Greenlight\Expect\Expect;
use Greenlight\Tests\Fixture\Doubles\Calculator;
use Greenlight\Tests\Fixture\Doubles\Wide;

final class SpyTest
{
    #[Test]
    public function recordsEveryCallInOrderWithArguments(): void
    {
        $doubles = new Doubles();
        $spy = $doubles->spy(Calculator::class);

        $spy->add(1, 2);
        $spy->describe('first');
        $spy->add(3, 4);

        new Expect()->that($doubles->callsTo($spy, 'add'))->toBe([[1, 2], [3, 4]])
            ->and($doubles->callsTo($spy, 'describe'))->toBe([['first']]);

        $doubles->dispose();
    }

    #[Test]
    public function anUncalledMethodHasNoRecordedCalls(): void
    {
        $doubles = new Doubles();
        $spy = $doubles->spy(Calculator::class);

        new Expect()->that($doubles->callsTo($spy, 'add'))->toBe([]);

        $doubles->dispose();
    }

    #[Test]
    public function variadicArgumentsAreRecordedFlattened(): void
    {
        $doubles = new Doubles();
        $spy = $doubles->spy(Wide::class);

        $spy->variadic('first', 1, 2, 3);

        new Expect()->that($doubles->callsTo($spy, 'variadic'))->toBe([['first', 1, 2, 3]]);

        $doubles->dispose();
    }

    #[Test]
    public function callsToRejectsForeignObjects(): void
    {
        $doubles = new Doubles();
        $foreign = new \stdClass();

        new Expect()->that(static fn(): array => $doubles->callsTo($foreign, 'add'))
            ->toThrow(DoublesError::class, '/not created by this Doubles factory/');
    }

    #[Test]
    public function spyRecordingsWorkWithExpectDirectly(): void
    {
        $doubles = new Doubles();
        $spy = $doubles->spy(Calculator::class);

        $spy->add(10, 20);

        new Expect()->that($doubles->callsTo($spy, 'add'))->toHaveCount(1)
            ->and($doubles->callsTo($spy, 'add')[0])->toEqual([10, 20]);

        $doubles->dispose();
    }
}
