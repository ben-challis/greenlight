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

        Expect::that($doubles->callsTo($spy, 'notify'))->because('records every call in order with arguments')->toBe([['ops', 'first'], ['dev', 'second']])
            ->and($doubles->callsTo($spy, 'flush'))->toBe([[]]);

        $doubles->dispose();
    }

    #[Test]
    public function anUncalledMethodHasNoRecordedCalls(): void
    {
        $doubles = new Doubles();
        $spy = $doubles->spy(Notifier::class);

        Expect::that($doubles->callsTo($spy, 'notify'))->because('an uncalled method has no recorded calls')->toBe([]);

        $doubles->dispose();
    }

    #[Test]
    public function variadicArgumentsAreRecordedFlattened(): void
    {
        $doubles = new Doubles();
        $spy = $doubles->spy(Notifier::class);

        $spy->tag('first', 1, 2, 3);

        Expect::that($doubles->callsTo($spy, 'tag'))->because('variadic arguments are recorded flattened')->toBe([['first', 1, 2, 3]]);

        $doubles->dispose();
    }

    #[Test]
    public function valueReturningMethodsCannotBeSpiedOn(): void
    {
        $doubles = new Doubles();
        $spy = $doubles->spy(Calculator::class);

        Expect::that(static fn(): int => $spy->add(1, 2))->because('value returning methods cannot be spied on')
            ->toThrow(DoublesError::class, '/Spies only record/');

        $doubles->dispose();
    }

    #[Test]
    public function callsToRejectsForeignObjects(): void
    {
        $doubles = new Doubles();
        $foreign = new \stdClass();

        Expect::that(static fn(): array => $doubles->callsTo($foreign, 'add'))->because('calls to rejects foreign objects')
            ->toThrow(DoublesError::class, '/Doubles factory did not create/');
    }

    #[Test]
    public function spyRecordingsWorkWithExpectDirectly(): void
    {
        $doubles = new Doubles();
        $spy = $doubles->spy(Notifier::class);

        $spy->notify('ops', 'deploy finished');

        Expect::that($doubles->callsTo($spy, 'notify'))->because('spy recordings work with expect directly')->toHaveCount(1)
            ->and($doubles->callsTo($spy, 'notify')[0])->toEqual(['ops', 'deploy finished']);

        $doubles->dispose();
    }
}
