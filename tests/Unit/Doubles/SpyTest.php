<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Doubles;

use Greenlight\Attribute\Test;
use Greenlight\Doubles\Doubles;
use Greenlight\Doubles\DoublesError;
use Greenlight\Expect\Expect;
use Greenlight\Tests\Fixture\Doubles\Calculator;
use Greenlight\Tests\Fixture\Doubles\Notifier;

final class SpyTest
{
    #[Test]
    public function recordsEveryCallInOrderWithArguments(): void
    {
        $doubles = new Doubles();
        $spy = $doubles->spy(Notifier::class);

        $spy->notify('ops', 'first');
        $spy->flush();
        $spy->notify('dev', 'second');

        new Expect()->that($doubles->callsTo($spy, 'notify'))->toBe([['ops', 'first'], ['dev', 'second']])
            ->and($doubles->callsTo($spy, 'flush'))->toBe([[]]);

        $doubles->dispose();
    }

    #[Test]
    public function anUncalledMethodHasNoRecordedCalls(): void
    {
        $doubles = new Doubles();
        $spy = $doubles->spy(Notifier::class);

        new Expect()->that($doubles->callsTo($spy, 'notify'))->toBe([]);

        $doubles->dispose();
    }

    #[Test]
    public function variadicArgumentsAreRecordedFlattened(): void
    {
        $doubles = new Doubles();
        $spy = $doubles->spy(Notifier::class);

        $spy->tag('first', 1, 2, 3);

        new Expect()->that($doubles->callsTo($spy, 'tag'))->toBe([['first', 1, 2, 3]]);

        $doubles->dispose();
    }

    #[Test]
    public function valueReturningMethodsCannotBeSpiedOn(): void
    {
        $doubles = new Doubles();
        $spy = $doubles->spy(Calculator::class);

        new Expect()->that(static fn(): int => $spy->add(1, 2))
            ->toThrow(DoublesError::class, '/Spies only record/');

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
        $spy = $doubles->spy(Notifier::class);

        $spy->notify('ops', 'deploy finished');

        new Expect()->that($doubles->callsTo($spy, 'notify'))->toHaveCount(1)
            ->and($doubles->callsTo($spy, 'notify')[0])->toEqual(['ops', 'deploy finished']);

        $doubles->dispose();
    }
}
