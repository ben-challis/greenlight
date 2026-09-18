<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\AllowParallel;
use Greenlight\Attribute\RequiresResource;
use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Sandbox\TemporaryDirectory;
use Greenlight\Tests\Support\FixturePath;
use Greenlight\Tests\Support\PhpStanProbe;

#[AllowParallel]
#[RequiresResource('analysis-process')]
final readonly class PhpStanMatcherSubjectTest
{
    public function __construct(private TemporaryDirectory $tempDirectory) {}

    #[Test]
    public function matcherSubjectTypesFollowExpectationChains(): void
    {
        $probes = PhpStanProbe::analyzeBatch(
            $this->tempDirectory,
            [
                'fluent chains' => [
                    'good' => <<<'PHP'
                        <?php

                        declare(strict_types=1);

                        use Greenlight\Expect\Expect;

                        function greenlightGoodSubjectProbe(): void
                        {
                            Expect::value('c0ffee')
                                ->toBeHexadecimal()
                                ->toHaveDigestLength(6);
                            Expect::value(1)
                                ->toBePositive()
                                ->toBe(1);
                        }
                        PHP,
                    'bad' => <<<'PHP'
                        <?php

                        declare(strict_types=1);

                        use Greenlight\Expect\Expect;

                        function greenlightBadSubjectProbe(): void
                        {
                            Expect::value(1)->toBePositive()
                                ->toBeHexadecimal();
                            Expect::value('c0ffee')->toHaveDigestLength(6)
                                ->toBePositive();
                        }
                        PHP,
                ],
                'temporal chains' => [
                    'good' => <<<'PHP'
                        <?php

                        declare(strict_types=1);

                        use Greenlight\Expect\Expect;

                        function greenlightGoodTemporalSubjectProbe(): void
                        {
                            Expect::calling(static fn(): string => 'c0ffee')->returnValue()->eventually()
                                ->within(1.0)
                                ->toBeHexadecimal();
                            Expect::calling(static fn(): int => 1)->returnValue()->consistently()
                                ->for(0.1)
                                ->toBePositive();
                        }
                        PHP,
                    'bad' => <<<'PHP'
                        <?php

                        declare(strict_types=1);

                        use Greenlight\Expect\Expect;

                        function greenlightBadTemporalSubjectProbe(): void
                        {
                            Expect::calling(static fn(): int => 1)->returnValue()->eventually()
                                ->within(1.0)
                                ->toBeHexadecimal();
                            Expect::calling(static fn(): int => 1)->returnValue()->eventually()
                                ->within(1.0)
                                ->toBePositive()
                                ->toBeHexadecimal();
                            Expect::calling(static fn(): int => 1)->returnValue()->consistently()
                                ->for(0.1)
                                ->toBe(1)
                                ->toBeHexadecimal();
                        }
                        PHP,
                ],
            ],
        );

        $probe = $probes['fluent chains'];
        Expect::value($probe->exitCode)->because('fluent chains preserve matcher subject types')->toBe(1);
        Expect::value($probe->goodPassed)->toBeTrue();
        Expect::value(\count($probe->errors))->toBe(2);
        Expect::value($probe->messages())->toContain('requires subject type string, but the subject has type int');

        $probe = $probes['temporal chains'];
        Expect::value($probe->exitCode)->because('temporal chains preserve matcher subject types')->toBe(1);
        Expect::value($probe->goodPassed)->toBeTrue();
        Expect::value(\count($probe->errors))->toBe(3);
        Expect::value($probe->messages())->toContain('requires subject type string, but the subject has type int');
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
                Expect::value($extension)->toAcceptSelf();
                Expect::value($extension)->toAcceptParent();
                Expect::value('value')->toAcceptSelfArgument($extension);
                Expect::value('value')->toAcceptParentArgument(new MatcherSubject());
            }
            PHP,
            <<<'PHP'
            <?php

            declare(strict_types=1);

            use Greenlight\Expect\Expect;
            use Greenlight\Tests\Fixture\PhpStanScopedMatcher\MatcherSubject;

            function greenlightBadScopedMatcherProbe(): void
            {
                Expect::value(new MatcherSubject())->toAcceptSelf();
                Expect::value('value')->toAcceptParent();
                Expect::value('value')->toAcceptSelfArgument(new MatcherSubject());
                Expect::value('value')->toAcceptParentArgument(new \stdClass());
            }
            PHP,
            FixturePath::get('PhpStanScopedMatcher/probe.neon'),
        );

        Expect::value($probe->exitCode)->because('relative matcher types use the closure scope')->toBe(1);
        Expect::value($probe->goodPassed)->toBeTrue();
        Expect::value(\count($probe->errors))->toBe(4);
        Expect::value($probe->messages())
            ->toContain('requires subject type Greenlight\\Tests\\Fixture\\PhpStanScopedMatcher\\ScopedMatcherExtension')
            ->toContain('expects Greenlight\\Tests\\Fixture\\PhpStanScopedMatcher\\MatcherSubject');
    }
}
