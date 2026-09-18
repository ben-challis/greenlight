<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\AllowParallel;
use Greenlight\Attribute\RequiresResource;
use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Sandbox\TemporaryDirectory;
use Greenlight\Tests\Support\PhpStanProbe;

#[AllowParallel]
#[RequiresResource('analysis-process')]
final readonly class PhpStanExpectationArgumentRuleTest
{
    public function __construct(private TemporaryDirectory $tempDirectory) {}

    #[Test]
    public function expectationArgumentsMustMatchRuntimeConstraints(): void
    {
        $probes = PhpStanProbe::analyzeBatch(
            $this->tempDirectory,
            [
                'pattern, JSON, and duration arguments' => [
                    'good' => <<<'PHP'
                        <?php

                        declare(strict_types=1);

                        use Greenlight\Expect\Expect;

                        function greenlightGoodExpectationArgumentProbe(string $pattern, string $json, float $duration): void
                        {
                            Expect::value('greenlight')->toMatch('/green/');
                            Expect::calling(static fn() => throw new DomainException('greenlight'))
                                ->toThrow(DomainException::class, matching: '/green/');
                            Expect::value('{}')->toMatchJson('{}');
                            Expect::calling(static fn(): bool => true)->returnValue()->eventually()
                                ->pollEvery(0.001)
                                ->within(0.001)
                                ->toBeTrue();
                            Expect::calling(static fn(): bool => true)->returnValue()->consistently()
                                ->pollEvery(0.001)
                                ->for(0.001)
                                ->toBeTrue();
                            Expect::value('greenlight')->toMatch($pattern);
                            Expect::value('{}')->toMatchJson($json);
                            Expect::calling(static fn(): bool => true)->returnValue()->eventually()->within($duration)->toBeTrue();
                        }
                        PHP,
                    'bad' => <<<'PHP'
                        <?php

                        declare(strict_types=1);

                        use Greenlight\Expect\Expect;

                        function greenlightBadExpectationArgumentProbe(): void
                        {
                            Expect::value('greenlight')->toMatch('/[/');
                            Expect::calling(static fn() => throw new DomainException('greenlight'))
                                ->toThrow(DomainException::class, matching: '/[/');
                            Expect::value('{}')->toMatchJson('{');
                            Expect::calling(static fn(): bool => true)->returnValue()->eventually()->pollEvery(0.0001)->within(1.0)->toBeTrue();
                            Expect::calling(static fn(): bool => true)->returnValue()->eventually()->within(0.0)->toBeTrue();
                            Expect::calling(static fn(): bool => true)->returnValue()->consistently()->pollEvery(-1.0)->for(1.0)->toBeTrue();
                            Expect::calling(static fn(): bool => true)->returnValue()->consistently()->for(0.0)->toBeTrue();
                            Expect::calling(static fn(): bool => true)->eventually()->pollEvery(0.0001)->within(1.0)->toReturn(true);
                            Expect::calling(static fn(): bool => true)->eventually()->within(0.0)->toReturn(true);
                            Expect::calling(static fn(): bool => true)->consistently()->pollEvery(-1.0)->for(1.0)->toReturn(true);
                            Expect::calling(static fn(): bool => true)->consistently()->for(0.0)->toReturn(true);
                        }
                        PHP,
                ],
                'tolerance and reason arguments' => [
                    'good' => <<<'PHP'
                        <?php

                        declare(strict_types=1);

                        use Greenlight\Expect\Expect;

                        /**
                         * @param non-empty-string $reason
                         */
                        function greenlightGoodToleranceAndReasonProbe(float $delta, string $reason): void
                        {
                            Expect::value(1.0)->toBeWithin(0.0, 1.0);
                            Expect::value(1.0)->toBeWithin($delta, 1.0);
                            Expect::value(true)->because('0')->toBeTrue();
                            Expect::value(true)->because($reason)->toBeTrue();
                            Expect::calling(static fn(): bool => true)->returnValue()->eventually()
                                ->within(0.001)
                                ->because($reason)
                                ->toBeTrue();
                        }
                        PHP,
                    'bad' => <<<'PHP'
                        <?php

                        declare(strict_types=1);

                        use Greenlight\Expect\Expect;

                        function greenlightBadToleranceAndReasonProbe(): void
                        {
                            Expect::value(1.0)->toBeWithin(-0.1, 1.0);
                            Expect::value(1.0)->toBeWithin(delta: INF, of: 1.0);
                            Expect::value(1.0)->toBeWithin(-INF, 1.0);
                            Expect::value(1.0)->toBeWithin(NAN, 1.0);
                            Expect::value(true)->because('   ')->toBeTrue();
                            Expect::calling(static fn(): bool => true)->eventually()->because('   ')->within(0.1)->toReturn(true);
                            Expect::calling(static fn(): bool => true)->returnValue()->consistently()->because('   ')->for(0.1)->toBeTrue();
                            Expect::calling(static fn(): bool => true)->returnValue()->eventually()
                                ->within(0.001)
                                ->because("\t\n")
                                ->toBeTrue();
                        }
                        PHP,
                ],
            ],
        );

        $probe = $probes['pattern, JSON, and duration arguments'];
        Expect::value($probe->exitCode)->because('constant expectation arguments must satisfy runtime constraints')->toBe(1);
        Expect::value($probe->goodPassed)->toBeTrue();
        Expect::value(\count($probe->errors))->toBe(11);
        Expect::value($probe->messages())->toContain('Regular expression "/[/" for toMatch() is invalid');
        Expect::value($probe->messages())->toContain('toMatchJson() requires valid expected JSON');
        Expect::value($probe->messages())->toContain('within() requires a finite duration greater than 0.000 seconds');

        $probe = $probes['tolerance and reason arguments'];
        Expect::value($probe->exitCode)->because('constant tolerances and reasons must satisfy runtime constraints')->toBe(1);
        Expect::value($probe->goodPassed)->toBeTrue();
        Expect::value(\count($probe->errors))->toBe(8);
        Expect::value($probe->messages())->toContain('toBeWithin() requires a finite tolerance of zero or more');
        Expect::value($probe->messages())->toContain('because() requires a non-empty reason');
    }
}
