<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\PhpStan;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Tests\Fixture\PhpStanExtension\DigestExtension;

/**
 * The calls below are typed by the PHPStan extension during this repo's own
 * static analysis run and dispatched through Expectation::__call at runtime,
 * proving both views of the fixture matchers agree.
 */
final class ExtensionMatcherDispatchTest
{
    #[Test]
    public function fixtureMatchersDispatchAndAnalyse(): void
    {
        Expect::install([new DigestExtension()]);

        try {
            Expect::that('c0ffee')->toBeHexadecimal()
                ->and('c0ffee')->toHaveDigestLength(6)
                ->and('not hex!')->not()->toBeHexadecimal();
        } finally {
            Expect::install([]);
        }
    }
}
