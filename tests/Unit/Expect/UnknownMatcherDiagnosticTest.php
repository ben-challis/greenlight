<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Expect;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expectation;

use function Greenlight\expect;

final class UnknownMatcherDiagnosticTest
{
    #[Test]
    public function unknownMatchersIdentifyTheMissingRegistration(): void
    {
        expect()->calling(
            static fn(): Expectation => expect(4)->__call('toBeUnavailableMatcher', []),
        )
            ->because('an unknown matcher MUST identify the missing native or extension registration')
            ->toThrow(
                \BadMethodCallException::class,
                message: 'Greenlight has no native or registered extension matcher named toBeUnavailableMatcher.',
            );

        expect()->calling(
            static fn(): Expectation => expect()->calling(static fn(): int => 4)->returnValue()->eventually()
                ->within(0.001)
                ->__call('toBeUnavailableMatcher', []),
        )
            ->because('a temporal unknown matcher MUST identify the missing native or extension registration')
            ->toThrow(
                \BadMethodCallException::class,
                message: 'Greenlight has no native or registered extension matcher named toBeUnavailableMatcher.',
            );
    }
}
