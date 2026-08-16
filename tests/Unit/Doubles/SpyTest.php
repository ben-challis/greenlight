<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Doubles;

use Greenlight\Attribute\Test;
use Greenlight\Doubles\Doubles;
use Greenlight\Doubles\DoublesError;
use Greenlight\Expect\Expect;
use Greenlight\Tests\Fixture\Doubles\Calculator;
use Greenlight\Tests\Fixture\Doubles\Notifier;

final readonly class SpyTest
{
    public function __construct(private Doubles $doubles) {}

    #[Test]
    public function recordsEveryCallInOrderWithArguments(): void
    {
        $spy = $this->doubles->spy(Notifier::class);

        $spy->notify('ops', 'first');
        $spy->flush();
        $spy->notify('dev', 'second');

        Expect::that($this->doubles->callsTo($spy, 'notify'))->because('records every call in order with arguments')->toBe([['ops', 'first'], ['dev', 'second']]);
        Expect::that($this->doubles->callsTo($spy, 'flush'))->toBe([[]]);
    }

    #[Test]
    public function anUncalledMethodHasNoRecordedCalls(): void
    {
        $spy = $this->doubles->spy(Notifier::class);

        Expect::that($this->doubles->callsTo($spy, 'notify'))->because('an uncalled method has no recorded calls')->toBe([]);
    }

    #[Test]
    public function variadicArgumentsAreRecordedFlattened(): void
    {
        $spy = $this->doubles->spy(Notifier::class);

        $spy->tag('first', 1, 2, 3);

        Expect::that($this->doubles->callsTo($spy, 'tag'))->because('variadic arguments are recorded flattened')->toBe([['first', 1, 2, 3]]);
    }

    #[Test]
    public function valueReturningMethodsCannotBeSpiedOn(): void
    {
        $spy = $this->doubles->spy(Calculator::class);

        Expect::that(static fn(): int => $spy->add(1, 2))->because('value returning methods cannot be spied on')
            ->toThrow(
                DoublesError::class,
                message: 'The spy of "' . Calculator::class . '" cannot supply a value for "add()". '
                    . 'Spies only record interactions. '
                    . 'Use mock() with explicit expectations for calls that return values.',
            );
    }

    #[Test]
    public function callsToRejectsForeignObjects(): void
    {
        $foreign = new \stdClass();

        Expect::that(fn(): array => $this->doubles->callsTo($foreign, 'add'))->because('calls to rejects foreign objects') // @phpstan-ignore greenlight.doubles.callsToMethod (deliberately invalid: tests runtime validation)
            ->toThrow(
                DoublesError::class,
                message: 'This Doubles factory did not create the stdClass instance.',
            );
    }

    #[Test]
    public function spyRecordingsWorkWithExpectDirectly(): void
    {
        $spy = $this->doubles->spy(Notifier::class);

        $spy->notify('ops', 'deploy finished');

        Expect::that($this->doubles->callsTo($spy, 'notify'))->because('spy recordings work with expect directly')->toHaveCount(1);
        Expect::that($this->doubles->callsTo($spy, 'notify')[0])->toEqual(['ops', 'deploy finished']);
    }
}
