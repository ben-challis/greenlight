<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\RequiresResource;
use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Sandbox\TemporaryDirectory;
use Greenlight\Tests\Support\PhpStanProbe;

#[RequiresResource('analysis-process')]
final readonly class PhpStanNativeMatcherSubjectRuleTest
{
    public function __construct(private TemporaryDirectory $tempDirectory) {}

    #[Test]
    public function nativeMatcherSubjectTypesFollowExpectationChains(): void
    {
        Expect::value('greenlight')->toContain(...['light']);
        Expect::value('greenlight')->toContain(...['needle' => 'light']);

        $probe = PhpStanProbe::analyze(
            $this->tempDirectory,
            <<<'PHP'
            <?php

            declare(strict_types=1);

            use Greenlight\Expect\Expect;

            function greenlightGoodNativeMatcherSubjectProbe(mixed $subject): void
            {
                Expect::value('greenlight')->toContain('light');
                Expect::value('greenlight')->toContain(...['light']);
                Expect::value('greenlight')->toContain(...['needle' => 'light']);
                Expect::value([1, 2])->toHaveCount(2);
                Expect::value(new ArrayIterator())->toBeEmpty();
                Expect::value('greenlight')->toHaveLength(10);
                Expect::value(['status' => 'green'])->toHaveKey('status');
                Expect::value(['status' => 'green'])->toContainSubset(['status' => 'green']);
                Expect::value(2)->toBeGreaterThan(1);
                Expect::value('greenlight')->toStartWith('green');
                Expect::value($subject)->toEndWith('light');
                Expect::calling(static fn(): string => 'greenlight')->returnValue()->eventually()
                    ->within(1.0)
                    ->toMatch('/green/');
                Expect::calling(static fn(): int => 2)->returnValue()->consistently()
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
                Expect::value(1)->toContain('1');
                Expect::value('greenlight')->toContain(1);
                Expect::value('greenlight')->toContain(...[1]);
                Expect::value('greenlight')->toContain(...['needle' => []]);
                Expect::value('greenlight')->toHaveCount(10);
                Expect::value(1)->toBeEmpty();
                Expect::value(1)->toHaveLength(1);
                Expect::value('greenlight')->toHaveKey(0);
                Expect::value('greenlight')->toContainSubset([]);
                Expect::value('1')->toBeGreaterThan(0);
                Expect::value('1')->toBeGreaterThanOrEqual(0);
                Expect::value('1')->toBeLessThan(2);
                Expect::value('1')->toBeLessThanOrEqual(2);
                Expect::value('1')->toBeWithin(1.0, 1.0);
                Expect::calling(static fn(): int => 1)->returnValue()->eventually()
                    ->within(1.0)
                    ->toMatch('/1/');
                Expect::value(1)->toStartWith('1');
                Expect::value(1)->toEndWith('1');
                Expect::value([])->toBeJson();
                Expect::calling(static fn(): array => [])->returnValue()->consistently()
                    ->for(0.1)
                    ->toMatchJson('{}');
            }
            PHP,
        );

        Expect::value($probe->exitCode)->because('native matchers require compatible subject types')->toBe(1);
        Expect::value($probe->goodPassed)->toBeTrue();
        Expect::value(\count($probe->errors))->toBe(19);
        Expect::value($probe->messages())->toContain('toContain() requires a string or iterable subject');
        Expect::value($probe->messages())->toContain('toContain() requires a string needle for a string subject');
        Expect::value($probe->messages())->toContain('toMatchJson() requires a string subject');
    }
}
