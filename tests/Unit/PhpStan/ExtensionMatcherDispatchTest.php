<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\PhpStan;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Expect\ExpectationRuntime;
use Greenlight\Test\Cleanup;
use Greenlight\Tests\Fixture\Expect\FakePollingClock;
use Greenlight\Tests\Fixture\PhpStanExtension\DigestExtension;

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

        Expect::that('c0ffee')->toBeHexadecimal()
            ->toHaveDigestLength(6);
        Expect::that('not hex!')->not()->toBeHexadecimal();
    }

    #[Test]
    public function temporalMatchersPreserveNamedExtensionArguments(): void
    {
        $clock = new FakePollingClock();
        $restoreExtensions = Expect::install([new DigestExtension()]);
        $this->cleanup->defer($restoreExtensions);

        ExpectationRuntime::withClock($clock, static function (): void {
            Expect::eventually(static fn(): string => 'c0ffee')
                ->within(0.100)
                ->toHaveDigestLength(length: 6);
            Expect::consistently(static fn(): string => 'c0ffee')
                ->for(0.001)
                ->toHaveDigestLength(length: 6);
        });

        Expect::that($clock->sleeps)
            ->because('extension matcher dispatch MUST preserve named arguments')
            ->toBe([0.001]);
    }
}
