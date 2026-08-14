<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\RequiresResource;
use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Fixture\TempDirectory;
use Greenlight\Tests\Support\PhpStanProbe;

#[RequiresResource('analysis-process')]
final readonly class PhpStanExpectationArgumentRuleTest
{
    public function __construct(private TempDirectory $tempDirectory) {}

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

        Expect::that($probe->exitCode)->because('constant expectation arguments must satisfy runtime constraints')->toBe(1)
            ->and($probe->goodPassed)->toBeTrue()
            ->and(\count($probe->errors))->toBe(7)
            ->and($probe->messages())->toContain('Regular expression "/[/" for toMatch() is invalid')
            ->and($probe->messages())->toContain('toMatchJson() requires valid expected JSON')
            ->and($probe->messages())->toContain('within() requires a finite duration greater than 0.000 seconds');
    }
}
