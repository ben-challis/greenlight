<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\RequiresResource;
use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Fixture\TempDirectory;
use Greenlight\Tests\Support\PhpStanProbe;

#[RequiresResource('analysis-process')]
final readonly class PhpStanMatcherSubjectTest
{
    public function __construct(private TempDirectory $tempDirectory) {}

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
                Expect::that('c0ffee')
                    ->toBeHexadecimal()
                    ->toHaveDigestLength(6);
                Expect::that(1)
                    ->toBePositive()
                    ->toBe(1);
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
                    ->toBePositive();
            }
            PHP,
        );

        Expect::that($probe->exitCode)->because('fluent chains preserve matcher subject types')->toBe(1);
        Expect::that($probe->goodPassed)->toBeTrue();
        Expect::that(\count($probe->errors))->toBe(2);
        Expect::that($probe->messages())->toContain('requires subject type string, but the subject has type int');
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

        Expect::that($probe->exitCode)->because('temporal chains preserve matcher subject types')->toBe(1);
        Expect::that($probe->goodPassed)->toBeTrue();
        Expect::that(\count($probe->errors))->toBe(3);
        Expect::that($probe->messages())->toContain('requires subject type string, but the subject has type int');
    }

    #[Test]
    public function relativeMatcherTypesUseTheClosureScope(): void
    {
        $probe = PhpStanProbe::analyze(
            $this->tempDirectory,
            <<<'PHP'
            <?php

            declare(strict_types=1);

            use Greenlight\Expect\Expect;
            use Greenlight\Tests\Fixture\PhpStanScopedMatcher\MatcherSubject;
            use Greenlight\Tests\Fixture\PhpStanScopedMatcher\ScopedMatcherExtension;

            function greenlightGoodScopedMatcherProbe(): void
            {
                $extension = new ScopedMatcherExtension();
                Expect::that($extension)->toAcceptSelf();
                Expect::that($extension)->toAcceptParent();
                Expect::that('value')->toAcceptSelfArgument($extension);
                Expect::that('value')->toAcceptParentArgument(new MatcherSubject());
            }
            PHP,
            <<<'PHP'
            <?php

            declare(strict_types=1);

            use Greenlight\Expect\Expect;
            use Greenlight\Tests\Fixture\PhpStanScopedMatcher\MatcherSubject;

            function greenlightBadScopedMatcherProbe(): void
            {
                Expect::that(new MatcherSubject())->toAcceptSelf();
                Expect::that('value')->toAcceptParent();
                Expect::that('value')->toAcceptSelfArgument(new MatcherSubject());
                Expect::that('value')->toAcceptParentArgument(new \stdClass());
            }
            PHP,
            \dirname(__DIR__) . '/Fixture/PhpStanScopedMatcher/probe.neon',
        );

        Expect::that($probe->exitCode)->because('relative matcher types use the closure scope')->toBe(1);
        Expect::that($probe->goodPassed)->toBeTrue();
        Expect::that(\count($probe->errors))->toBe(4);
        Expect::that($probe->messages())
            ->toContain('requires subject type Greenlight\\Tests\\Fixture\\PhpStanScopedMatcher\\ScopedMatcherExtension')
            ->toContain('expects Greenlight\\Tests\\Fixture\\PhpStanScopedMatcher\\MatcherSubject');
    }
}
