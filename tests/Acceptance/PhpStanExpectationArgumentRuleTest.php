<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\AllowParallel;
use Greenlight\Attribute\RequiresResource;
use Greenlight\Attribute\Test;
use Greenlight\Sandbox\TemporaryDirectory;
use Greenlight\Tests\Support\PhpStanProbe;

use function Greenlight\expect;

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

                        use function Greenlight\expect;

                        function greenlightGoodExpectationArgumentProbe(string $pattern, string $json, float $duration): void
                        {
                            expect('greenlight')->toMatch('/green/');
                            expect()->calling(static fn() => throw new DomainException('greenlight'))
                                ->toThrow(DomainException::class, matching: '/green/');
                            expect('{}')->toMatchJson('{}');
                            expect()->calling(static fn(): bool => true)->returnValue()->eventually()
                                ->pollEvery(0.001)
                                ->within(0.001)
                                ->toBeTrue();
                            expect()->calling(static fn(): bool => true)->returnValue()->consistently()
                                ->pollEvery(0.001)
                                ->for(0.001)
                                ->toBeTrue();
                            expect('greenlight')->toMatch($pattern);
                            expect('{}')->toMatchJson($json);
                            expect()->calling(static fn(): bool => true)->returnValue()->eventually()->within($duration)->toBeTrue();
                        }
                        PHP,
                    'bad' => <<<'PHP'
                        <?php

                        declare(strict_types=1);

                        use function Greenlight\expect;

                        function greenlightBadExpectationArgumentProbe(): void
                        {
                            expect('greenlight')->toMatch('/[/');
                            expect()->calling(static fn() => throw new DomainException('greenlight'))
                                ->toThrow(DomainException::class, matching: '/[/');
                            expect('{}')->toMatchJson('{');
                            expect()->calling(static fn(): bool => true)->returnValue()->eventually()->pollEvery(0.0001)->within(1.0)->toBeTrue();
                            expect()->calling(static fn(): bool => true)->returnValue()->eventually()->within(0.0)->toBeTrue();
                            expect()->calling(static fn(): bool => true)->returnValue()->consistently()->pollEvery(-1.0)->for(1.0)->toBeTrue();
                            expect()->calling(static fn(): bool => true)->returnValue()->consistently()->for(0.0)->toBeTrue();
                            expect()->calling(static fn(): bool => true)->eventually()->pollEvery(0.0001)->within(1.0)->toReturn(true);
                            expect()->calling(static fn(): bool => true)->eventually()->within(0.0)->toReturn(true);
                            expect()->calling(static fn(): bool => true)->consistently()->pollEvery(-1.0)->for(1.0)->toReturn(true);
                            expect()->calling(static fn(): bool => true)->consistently()->for(0.0)->toReturn(true);
                        }
                        PHP,
                ],
                'tolerance and reason arguments' => [
                    'good' => <<<'PHP'
                        <?php

                        declare(strict_types=1);

                        use function Greenlight\expect;

                        /**
                         * @param non-empty-string $reason
                         */
                        function greenlightGoodToleranceAndReasonProbe(float $delta, string $reason): void
                        {
                            expect(1.0)->toBeWithin(0.0, 1.0);
                            expect(1.0)->toBeWithin($delta, 1.0);
                            expect(true)->because('0')->toBeTrue();
                            expect(true)->because($reason)->toBeTrue();
                            expect()->calling(static fn(): bool => true)->returnValue()->eventually()
                                ->within(0.001)
                                ->because($reason)
                                ->toBeTrue();
                        }
                        PHP,
                    'bad' => <<<'PHP'
                        <?php

                        declare(strict_types=1);

                        use function Greenlight\expect;

                        function greenlightBadToleranceAndReasonProbe(): void
                        {
                            expect(1.0)->toBeWithin(-0.1, 1.0);
                            expect(1.0)->toBeWithin(delta: INF, of: 1.0);
                            expect(1.0)->toBeWithin(-INF, 1.0);
                            expect(1.0)->toBeWithin(NAN, 1.0);
                            expect(true)->because('   ')->toBeTrue();
                            expect()->calling(static fn(): bool => true)->eventually()->because('   ')->within(0.1)->toReturn(true);
                            expect()->calling(static fn(): bool => true)->returnValue()->consistently()->because('   ')->for(0.1)->toBeTrue();
                            expect()->calling(static fn(): bool => true)->returnValue()->eventually()
                                ->within(0.001)
                                ->because("\t\n")
                                ->toBeTrue();
                        }
                        PHP,
                ],
            ],
        );

        $probe = $probes['pattern, JSON, and duration arguments'];
        expect($probe->exitCode)->because('constant expectation arguments must satisfy runtime constraints')->toBe(1);
        expect($probe->goodPassed)->toBeTrue();
        expect(\count($probe->errors))->toBe(11);
        expect($probe->messages())->toContain('Regular expression "/[/" for toMatch() is invalid');
        expect($probe->messages())->toContain('toMatchJson() requires valid expected JSON');
        expect($probe->messages())->toContain('within() requires a finite duration greater than 0.000 seconds');

        $probe = $probes['tolerance and reason arguments'];
        expect($probe->exitCode)->because('constant tolerances and reasons must satisfy runtime constraints')->toBe(1);
        expect($probe->goodPassed)->toBeTrue();
        expect(\count($probe->errors))->toBe(8);
        expect($probe->messages())->toContain('toBeWithin() requires a finite tolerance of zero or more');
        expect($probe->messages())->toContain('because() requires a non-empty reason');
    }
}
