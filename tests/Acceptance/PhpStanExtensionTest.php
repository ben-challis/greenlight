<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Fixture\TempDirectory;
use Greenlight\Tests\Support\PhpStanProbe;

final readonly class PhpStanExtensionTest
{
    public function __construct(private TempDirectory $tempDirectory) {}

    #[Test]
    public function reflectedMatcherSignaturesAreEnforced(): void
    {
        $probe = PhpStanProbe::analyze(
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

        Expect::that($probe->exitCode)->because('reflected matcher signatures are enforced')->toBe(1)
            ->and($probe->goodPassed)->toBeTrue()
            ->and(\count($probe->errors))->toBe(2)
            ->and($probe->messages())->toContain('toHaveDigestLength() expects int, string given')
            ->toContain('invoked with 1 parameter, 0 required');
    }

    #[Test]
    public function matcherSubjectTypesFollowFluentChains(): void
    {
        $probe = PhpStanProbe::analyze(
            $this->tempDirectory,
            <<<'PHP'
            <?php

            declare(strict_types=1);

            use Greenlight\Expect\Expect;

            function greenlightGoodSubjectProbe(): void
            {
                Expect::that('c0ffee')->toBeHexadecimal()
                    ->and(1)->toBePositive();
                Expect::that(1)->toBePositive()
                    ->and('c0ffee')->toHaveDigestLength(6);
            }
            PHP,
            <<<'PHP'
            <?php

            declare(strict_types=1);

            use Greenlight\Expect\Expect;

            function greenlightBadSubjectProbe(): void
            {
                Expect::that(1)->toBePositive()
                    ->toBeHexadecimal();
                Expect::that('c0ffee')->toHaveDigestLength(6)
                    ->and(1)->toBeHexadecimal();
            }
            PHP,
        );

        Expect::that($probe->exitCode)->because('fluent chains preserve matcher subject types')->toBe(1)
            ->and($probe->goodPassed)->toBeTrue()
            ->and(\count($probe->errors))->toBe(2)
            ->and($probe->messages())->toContain('requires subject type string, but the subject has type int');
    }

    #[Test]
    public function temporalMatcherSignaturesAreEnforced(): void
    {
        $probe = PhpStanProbe::analyze(
            $this->tempDirectory,
            <<<'PHP'
            <?php

            declare(strict_types=1);

            use Greenlight\Expect\Expect;

            function greenlightGoodTemporalProbe(): void
            {
                Expect::eventually(static fn(): string => 'c0ffee')
                    ->within(1.0)
                    ->toHaveDigestLength(6);
                Expect::consistently(static fn(): string => 'c0ffee')
                    ->for(0.1)
                    ->toBeHexadecimal();
            }
            PHP,
            <<<'PHP'
            <?php

            declare(strict_types=1);

            use Greenlight\Expect\Expect;

            function greenlightBadTemporalProbe(): void
            {
                Expect::eventually(static fn(): string => 'c0ffee')
                    ->within(1.0)
                    ->toHaveDigestLength('six');
                Expect::consistently(static fn(): string => 'c0ffee')
                    ->for(0.1)
                    ->toBeHexadecimal(123);
            }
            PHP,
        );

        Expect::that($probe->exitCode)->because('temporal matcher signatures are enforced')->toBe(1)
            ->and($probe->goodPassed)->toBeTrue()
            ->and(\count($probe->errors))->toBe(2)
            ->and($probe->messages())->toContain('toHaveDigestLength() expects int, string given')
            ->toContain('invoked with 1 parameter, 0 required');
    }

    #[Test]
    public function matcherSubjectTypesFollowTemporalChains(): void
    {
        $probe = PhpStanProbe::analyze(
            $this->tempDirectory,
            <<<'PHP'
            <?php

            declare(strict_types=1);

            use Greenlight\Expect\Expect;

            function greenlightGoodTemporalSubjectProbe(): void
            {
                Expect::eventually(static fn(): string => 'c0ffee')
                    ->within(1.0)
                    ->toBeHexadecimal();
                Expect::consistently(static fn(): int => 1)
                    ->for(0.1)
                    ->toBePositive();
            }
            PHP,
            <<<'PHP'
            <?php

            declare(strict_types=1);

            use Greenlight\Expect\Expect;

            function greenlightBadTemporalSubjectProbe(): void
            {
                Expect::eventually(static fn(): int => 1)
                    ->within(1.0)
                    ->toBeHexadecimal();
                Expect::eventually(static fn(): int => 1)
                    ->within(1.0)
                    ->toBePositive()
                    ->toBeHexadecimal();
                Expect::consistently(static fn(): int => 1)
                    ->for(0.1)
                    ->toBe(1)
                    ->toBeHexadecimal();
            }
            PHP,
        );

        Expect::that($probe->exitCode)->because('temporal chains preserve matcher subject types')->toBe(1)
            ->and($probe->goodPassed)->toBeTrue()
            ->and(\count($probe->errors))->toBe(3)
            ->and($probe->messages())->toContain('requires subject type string, but the subject has type int');
    }
}
