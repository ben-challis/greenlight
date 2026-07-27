<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\PhpStan;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Tests\Fixture\PhpStanExtension\DigestExtension;

/**
 * PHPStan checks these calls through the extension. Runtime dispatch uses
 * Expectation::__call.
 */
final class ExtensionMatcherDispatchTest
{
    #[Test]
    public function fixtureMatchersDispatchAndAnalyse(): void
    {
        Expect::install([new DigestExtension()]);

        try {
            Expect::that('c0ffee')->toBeHexadecimal()
                ->toHaveDigestLength(6)
                ->and('not hex!')->not()->toBeHexadecimal();
        } finally {
            Expect::install([]);
        }
    }
}
