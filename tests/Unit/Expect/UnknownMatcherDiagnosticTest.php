<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Expect;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Expect\Expectation;

final class UnknownMatcherDiagnosticTest
{
    #[Test]
    public function unknownMatchersIdentifyTheMissingRegistration(): void
    {
        Expect::that(
            static fn(): Expectation => Expect::that(4)->__call('toBeUnavailableMatcher', []),
        )
            ->because('an unknown matcher MUST identify the missing native or extension registration')
            ->toThrow(
                \BadMethodCallException::class,
                message: 'Greenlight has no native or registered extension matcher named toBeUnavailableMatcher.',
            );

        Expect::that(
            static fn(): Expectation => Expect::eventually(static fn(): int => 4)
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
