<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\RequiresResource;
use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Fixture\TempDirectory;
use Greenlight\Tests\Support\PhpStanProbe;

#[RequiresResource('analysis-process')]
final readonly class PhpStanNativeMatcherSubjectRuleTest
{
    public function __construct(private TempDirectory $tempDirectory) {}

    #[Test]
    public function nativeMatcherSubjectTypesFollowExpectationChains(): void
    {
        $probe = PhpStanProbe::analyze(
            $this->tempDirectory,
            <<<'PHP'
            <?php

            declare(strict_types=1);

            use Greenlight\Expect\Expect;

            function greenlightGoodNativeMatcherSubjectProbe(mixed $subject): void
            {
                Expect::that('greenlight')->toContain('light');
                Expect::that([1, 2])->toHaveCount(2);
                Expect::that(new ArrayIterator())->toBeEmpty();
                Expect::that('greenlight')->toHaveLength(10);
                Expect::that(['status' => 'green'])->toHaveKey('status');
                Expect::that(['status' => 'green'])->toContainSubset(['status' => 'green']);
                Expect::that(2)->toBeGreaterThan(1);
                Expect::that('greenlight')->toStartWith('green');
                Expect::that($subject)->toEndWith('light');
                Expect::eventually(static fn(): string => 'greenlight')
                    ->within(1.0)
                    ->toMatch('/green/');
                Expect::consistently(static fn(): int => 2)
                    ->for(0.1)
                    ->toBeWithin(1.0, 2.0);
            }
            PHP,
            <<<'PHP'
            <?php

            declare(strict_types=1);

            use Greenlight\Expect\Expect;

            function greenlightBadNativeMatcherSubjectProbe(): void
            {
                Expect::that(1)->toContain('1');
                Expect::that('greenlight')->toContain(1);
                Expect::that('greenlight')->toHaveCount(10);
                Expect::that(1)->toBeEmpty();
                Expect::that(1)->toHaveLength(1);
                Expect::that('greenlight')->toHaveKey(0);
                Expect::that('greenlight')->toContainSubset([]);
                Expect::that('1')->toBeGreaterThan(0);
                Expect::that('1')->toBeGreaterThanOrEqual(0);
                Expect::that('1')->toBeLessThan(2);
                Expect::that('1')->toBeLessThanOrEqual(2);
                Expect::that('1')->toBeWithin(1.0, 1.0);
                Expect::eventually(static fn(): int => 1)
                    ->within(1.0)
                    ->toMatch('/1/');
                Expect::that(1)->toStartWith('1');
                Expect::that(1)->toEndWith('1');
                Expect::that([])->toBeJson();
                Expect::consistently(static fn(): array => [])
                    ->for(0.1)
                    ->toMatchJson('{}');
            }
            PHP,
        );

        Expect::that($probe->exitCode)->because('native matchers require compatible subject types')->toBe(1);
        Expect::that($probe->goodPassed)->toBeTrue();
        Expect::that(\count($probe->errors))->toBe(17);
        Expect::that($probe->messages())->toContain('toContain() requires a string or iterable subject');
        Expect::that($probe->messages())->toContain('toContain() requires a string needle for a string subject');
        Expect::that($probe->messages())->toContain('toMatchJson() requires a string subject');
    }
}
