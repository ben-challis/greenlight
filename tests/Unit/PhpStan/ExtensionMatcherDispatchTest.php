<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\PhpStan;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Test\Cleanup;
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
}
