<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\PhpStan;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Expect\ExpectationRuntime;
use Greenlight\Test\Cleanup;
use Greenlight\Tests\Fixture\Expect\FakeClock;
use Greenlight\Tests\Fixture\PhpStanExtension\DigestExtension;

use function Greenlight\expect;

/**
 * PHPStan checks these calls through the extension. Runtime dispatch uses
 * Expectation::__call.
 */
final readonly class ExtensionMatcherDispatchTest
{
    public function __construct(private Cleanup $cleanup) {}

    #[Test]
    public function fixtureMatchersDispatchAndAnalyze(): void
    {
        $restoreExtensions = Expect::install([new DigestExtension()]);
        $this->cleanup->defer($restoreExtensions);

        expect('c0ffee')->toBeHexadecimal()
            ->toHaveDigestLength(6);
        expect('not hex!')->not()->toBeHexadecimal();
    }

    #[Test]
    public function temporalMatchersPreserveNamedExtensionArguments(): void
    {
        $clock = new FakeClock();
        $restoreExtensions = Expect::install([new DigestExtension()]);
        $this->cleanup->defer($restoreExtensions);

        ExpectationRuntime::withClock($clock, static function (): void {
            expect()->calling(static fn(): string => 'c0ffee')->returnValue()->eventually()
                ->within(0.100)
                ->toHaveDigestLength(length: 6);
            expect()->calling(static fn(): string => 'c0ffee')->returnValue()->consistently()
                ->for(0.001)
                ->toHaveDigestLength(length: 6);
        });

        expect($clock->sleeps)
            ->because('extension matcher dispatch MUST preserve named arguments')
            ->toBe([0.001]);
    }
}
