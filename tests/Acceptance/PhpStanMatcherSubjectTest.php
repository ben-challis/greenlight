<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\AllowParallel;
use Greenlight\Attribute\RequiresResource;
use Greenlight\Attribute\Test;
use Greenlight\Sandbox\TemporaryDirectory;
use Greenlight\Tests\Support\FixturePath;
use Greenlight\Tests\Support\PhpStanProbe;

use function Greenlight\expect;

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

                        use function Greenlight\expect;

                        function greenlightGoodSubjectProbe(): void
                        {
                            expect('c0ffee')
                                ->toBeHexadecimal()
                                ->toHaveDigestLength(6);
                            expect(1)
                                ->toBePositive()
                                ->toBe(1);
                        }
                        PHP,
                    'bad' => <<<'PHP'
                        <?php

                        declare(strict_types=1);

                        use function Greenlight\expect;

                        function greenlightBadSubjectProbe(): void
                        {
                            expect(1)->toBePositive()
                                ->toBeHexadecimal();
                            expect('c0ffee')->toHaveDigestLength(6)
                                ->toBePositive();
                        }
                        PHP,
                ],
                'temporal chains' => [
                    'good' => <<<'PHP'
                        <?php

                        declare(strict_types=1);

                        use function Greenlight\expect;

                        function greenlightGoodTemporalSubjectProbe(): void
                        {
                            expect()->calling(static fn(): string => 'c0ffee')->returnValue()->eventually()
                                ->within(1.0)
                                ->toBeHexadecimal();
                            expect()->calling(static fn(): int => 1)->returnValue()->consistently()
                                ->for(0.1)
                                ->toBePositive();
                        }
                        PHP,
                    'bad' => <<<'PHP'
                        <?php

                        declare(strict_types=1);

                        use function Greenlight\expect;

                        function greenlightBadTemporalSubjectProbe(): void
                        {
                            expect()->calling(static fn(): int => 1)->returnValue()->eventually()
                                ->within(1.0)
                                ->toBeHexadecimal();
                            expect()->calling(static fn(): int => 1)->returnValue()->eventually()
                                ->within(1.0)
                                ->toBePositive()
                                ->toBeHexadecimal();
                            expect()->calling(static fn(): int => 1)->returnValue()->consistently()
                                ->for(0.1)
                                ->toBe(1)
                                ->toBeHexadecimal();
                        }
                        PHP,
                ],
            ],
        );

        $probe = $probes['fluent chains'];
        expect($probe->exitCode)->because('fluent chains preserve matcher subject types')->toBe(1);
        expect($probe->goodPassed)->toBeTrue();
        expect(\count($probe->errors))->toBe(2);
        expect($probe->messages())->toContain('requires subject type string, but the subject has type int');

        $probe = $probes['temporal chains'];
        expect($probe->exitCode)->because('temporal chains preserve matcher subject types')->toBe(1);
        expect($probe->goodPassed)->toBeTrue();
        expect(\count($probe->errors))->toBe(3);
        expect($probe->messages())->toContain('requires subject type string, but the subject has type int');
    }

    #[Test]
    public function relativeMatcherTypesUseTheClosureScope(): void
    {
        $probe = PhpStanProbe::analyze(
            $this->tempDirectory,
            <<<'PHP'
            <?php

            declare(strict_types=1);

            use Greenlight\Tests\Fixture\PhpStanScopedMatcher\MatcherSubject;
            use Greenlight\Tests\Fixture\PhpStanScopedMatcher\ScopedMatcherExtension;

            use function Greenlight\expect;

            function greenlightGoodScopedMatcherProbe(): void
            {
                $extension = new ScopedMatcherExtension();
                expect($extension)->toAcceptSelf();
                expect($extension)->toAcceptParent();
                expect('value')->toAcceptSelfArgument($extension);
                expect('value')->toAcceptParentArgument(new MatcherSubject());
            }
            PHP,
            <<<'PHP'
            <?php

            declare(strict_types=1);

            use Greenlight\Tests\Fixture\PhpStanScopedMatcher\MatcherSubject;

            use function Greenlight\expect;

            function greenlightBadScopedMatcherProbe(): void
            {
                expect(new MatcherSubject())->toAcceptSelf();
                expect('value')->toAcceptParent();
                expect('value')->toAcceptSelfArgument(new MatcherSubject());
                expect('value')->toAcceptParentArgument(new \stdClass());
            }
            PHP,
            FixturePath::get('PhpStanScopedMatcher/probe.neon'),
        );

        expect($probe->exitCode)->because('relative matcher types use the closure scope')->toBe(1);
        expect($probe->goodPassed)->toBeTrue();
        expect(\count($probe->errors))->toBe(4);
        expect($probe->messages())
            ->toContain('requires subject type Greenlight\\Tests\\Fixture\\PhpStanScopedMatcher\\ScopedMatcherExtension')
            ->toContain('expects Greenlight\\Tests\\Fixture\\PhpStanScopedMatcher\\MatcherSubject');
    }
}
