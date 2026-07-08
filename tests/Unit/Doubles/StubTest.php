<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Doubles;

use Greenlight\Attribute\NoExpectations;
use Greenlight\Attribute\Test;
use Greenlight\Doubles\Doubles;
use Greenlight\Doubles\DoublesError;
use Greenlight\Expect\Expect;
use Greenlight\Tests\Fixture\Doubles\Stubbable;

final class StubTest
{
    #[Test]
    public function satisfiesTheTypeWithoutRunningAnything(): void
    {
        $doubles = new Doubles();
        $stub = $doubles->stub(Stubbable::class);

        Expect::that($stub)->toBeInstanceOf(Stubbable::class);

        $doubles->dispose();
    }

    #[Test]
    public function anyCallIsAnAuthoringError(): void
    {
        $doubles = new Doubles();
        $stub = $doubles->stub(Stubbable::class);

        Expect::that(static fn(): string => $stub->name())
            ->toThrow(DoublesError::class, '/must never be interacted with/');

        $doubles->dispose();
    }

    #[Test]
    public function evenVoidCallsAreAuthoringErrors(): void
    {
        $doubles = new Doubles();
        $stub = $doubles->stub(Stubbable::class);

        Expect::that(static function () use ($stub): void {
            $stub->touch();
        })->toThrow(DoublesError::class, '/must never be interacted with/');

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
