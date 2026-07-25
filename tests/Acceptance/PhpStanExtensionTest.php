<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Fixture\TempDirectory;
use Greenlight\Tests\Support\PhpStanProbe;

/**
 * Runs the real PHPStan binary with the shipped extension against probe code
 * calling the fixture matchers: correct calls must analyse clean and a wrong
 * argument type must be flagged, proving the reflected signatures are
 * enforced rather than swallowed by the __call fallback.
 */
final readonly class PhpStanExtensionTest
{
    public function __construct(private TempDirectory $tempDirectory) {}

    #[Test]
    public function reflectedMatcherSignaturesAreEnforced(): void
    {
        $probe = PhpStanProbe::analyse(
            $this->tempDirectory,
            <<<'PHP'
            <?php

            declare(strict_types=1);

            use Greenlight\Expect\Expect;

            function greenlightGoodProbe(): void
            {
                Expect::that('c0ffee')->toBeHexadecimal()
                    ->and('c0ffee')->toHaveDigestLength(6);
            }
            PHP,
            <<<'PHP'
            <?php

            declare(strict_types=1);

            use Greenlight\Expect\Expect;

            function greenlightBadProbe(): void
            {
                Expect::that('c0ffee')->toHaveDigestLength('six')
                    ->and('c0ffee')->toBeHexadecimal(123);
            }
            PHP,
        );

        Expect::that($probe->exitCode)->toBe(1)
            ->and($probe->goodPassed)->toBeTrue()
            ->and(\count($probe->errors))->toBe(2)
            ->and($probe->messages())->toContain('toHaveDigestLength() expects int, string given')
            ->toContain('invoked with 1 parameter, 0 required');
    }
}
