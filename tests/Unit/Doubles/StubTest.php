<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Doubles;

use Greenlight\Attribute\NoExpectations;
use Greenlight\Attribute\Test;
use Greenlight\Doubles\Doubles;
use Greenlight\Doubles\InvalidDoubleUsage;
use Greenlight\Expect\Expect;
use Greenlight\Tests\Fixture\Doubles\Stubbable;

final class StubTest
{
    #[Test]
    public function satisfiesTheTypeWithoutRunningAnything(): void
    {
        $doubles = new Doubles();
        $stub = $doubles->stub(Stubbable::class);

        Expect::that($stub)->because('satisfies the type without running anything')->toBeInstanceOf(Stubbable::class);

        $doubles->dispose();
    }

    #[Test]
    public function anyCallIsAnAuthoringError(): void
    {
        $doubles = new Doubles();
        $stub = $doubles->stub(Stubbable::class);

        Expect::that(static fn(): string => $stub->name())->because('each call is an authoring error')
            ->toThrow(
                InvalidDoubleUsage::class,
                message: 'Code called "name()" on the stub of "' . Stubbable::class . '". '
                    . 'Stubs only satisfy a type. '
                    . 'Use mock() with explicit expectations for interactions.',
            );

        $doubles->dispose();
    }

    #[Test]
    public function evenVoidCallsAreAuthoringErrors(): void
    {
        $doubles = new Doubles();
        $stub = $doubles->stub(Stubbable::class);

        Expect::that(static function () use ($stub): void {
            $stub->touch();
        })->because('even void calls are authoring errors')->toThrow(
            InvalidDoubleUsage::class,
            message: 'Code called "touch()" on the stub of "' . Stubbable::class . '". '
                . 'Stubs only satisfy a type. '
                . 'Use mock() with explicit expectations for interactions.',
        );

        $doubles->dispose();
    }

    #[Test]
    #[NoExpectations]
    public function anUntouchedStubVerifiesCleanly(): void
    {
        $doubles = new Doubles();
        $doubles->stub(Stubbable::class);

        $doubles->dispose();
    }
}
