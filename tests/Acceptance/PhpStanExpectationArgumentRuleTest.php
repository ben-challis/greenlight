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
    public function constantExpectationArgumentsMustBeValid(): void
    {
        $probe = PhpStanProbe::analyze(
            $this->tempDirectory,
            <<<'PHP'
            <?php

            declare(strict_types=1);

            use Greenlight\Expect\Expect;

            function greenlightGoodExpectationArgumentProbe(string $pattern, string $json, float $duration): void
            {
                Expect::that('greenlight')->toMatch('/green/');
                Expect::that(static fn() => throw new DomainException('greenlight'))
                    ->toThrow(DomainException::class, matching: '/green/');
                Expect::that('{}')->toMatchJson('{}');
                Expect::eventually(static fn(): bool => true)
                    ->pollEvery(0.001)
                    ->within(0.001)
                    ->toBeTrue();
                Expect::consistently(static fn(): bool => true)
                    ->pollEvery(0.001)
                    ->for(0.001)
                    ->toBeTrue();
                Expect::that('greenlight')->toMatch($pattern);
                Expect::that('{}')->toMatchJson($json);
                Expect::eventually(static fn(): bool => true)->within($duration)->toBeTrue();
            }
            PHP,
            <<<'PHP'
            <?php

            declare(strict_types=1);

            use Greenlight\Expect\Expect;

            function greenlightBadExpectationArgumentProbe(): void
            {
                Expect::that('greenlight')->toMatch('/[/');
                Expect::that(static fn() => throw new DomainException('greenlight'))
                    ->toThrow(DomainException::class, matching: '/[/');
                Expect::that('{}')->toMatchJson('{');
                Expect::eventually(static fn(): bool => true)->pollEvery(0.0001)->within(1.0)->toBeTrue();
                Expect::eventually(static fn(): bool => true)->within(0.0)->toBeTrue();
                Expect::consistently(static fn(): bool => true)->pollEvery(-1.0)->for(1.0)->toBeTrue();
                Expect::consistently(static fn(): bool => true)->for(0.0)->toBeTrue();
            }
            PHP,
        );

        Expect::that($probe->exitCode)->because('constant expectation arguments must satisfy runtime constraints')->toBe(1);
        Expect::that($probe->goodPassed)->toBeTrue();
        Expect::that(\count($probe->errors))->toBe(7);
        Expect::that($probe->messages())->toContain('Regular expression "/[/" for toMatch() is invalid');
        Expect::that($probe->messages())->toContain('toMatchJson() requires valid expected JSON');
        Expect::that($probe->messages())->toContain('within() requires a finite duration greater than 0.000 seconds');
    }

    #[Test]
    public function toleranceAndReasonArgumentsMustMatchRuntimeConstraints(): void
    {
        $probe = PhpStanProbe::analyze(
            $this->tempDirectory,
            <<<'PHP'
            <?php

            declare(strict_types=1);

            use Greenlight\Expect\Expect;

            /**
             * @param non-empty-string $reason
             */
            function greenlightGoodToleranceAndReasonProbe(float $delta, string $reason): void
            {
                Expect::that(1.0)->toBeWithin(0.0, 1.0);
                Expect::that(1.0)->toBeWithin($delta, 1.0);
                Expect::that(true)->because('0')->toBeTrue();
                Expect::that(true)->because($reason)->toBeTrue();
                Expect::eventually(static fn(): bool => true)
                    ->within(0.001)
                    ->because($reason)
                    ->toBeTrue();
            }
            PHP,
            <<<'PHP'
            <?php

            declare(strict_types=1);

            use Greenlight\Expect\Expect;

            function greenlightBadToleranceAndReasonProbe(): void
            {
                Expect::that(1.0)->toBeWithin(-0.1, 1.0);
                Expect::that(1.0)->toBeWithin(delta: INF, of: 1.0);
                Expect::that(1.0)->toBeWithin(-INF, 1.0);
                Expect::that(1.0)->toBeWithin(NAN, 1.0);
                Expect::that(true)->because('   ')->toBeTrue();
                Expect::eventually(static fn(): bool => true)
                    ->within(0.001)
                    ->because("\t\n")
                    ->toBeTrue();
            }
            PHP,
        );

        Expect::that($probe->exitCode)->because('constant tolerances and reasons must satisfy runtime constraints')->toBe(1);
        Expect::that($probe->goodPassed)->toBeTrue();
        Expect::that(\count($probe->errors))->toBe(6);
        Expect::that($probe->messages())->toContain('toBeWithin() requires a finite tolerance of zero or more');
        Expect::that($probe->messages())->toContain('because() requires a non-empty reason');
    }
}
