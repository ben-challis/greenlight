<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Doubles;

use Greenlight\Attribute\Test;
use Greenlight\Doubles\Doubles;
use Greenlight\Doubles\DoublesError;
use Greenlight\Expect\Expect;
use Greenlight\Tests\Fixture\Doubles\Notifier;

final readonly class SpyMethodValidationTest
{
    public function __construct(private Doubles $doubles) {}

    #[Test]
    public function rejectsAnUnknownMethodInsteadOfReportingThatItWasNotCalled(): void
    {
        $spy = $this->doubles->spy(Notifier::class);

        Expect::that(fn(): array => $this->doubles->callsTo($spy, 'notifiy')) // @phpstan-ignore greenlight.doubles.callsToMethod (deliberately invalid: tests runtime validation)
            ->because('a misspelled method MUST NOT look like an uncalled method')
            ->toThrow(
                DoublesError::class,
                message: 'Greenlight\Tests\Fixture\Doubles\Notifier has no method notifiy(). '
                . 'Doubles cannot inspect calls to it.',
            );
    }
}
