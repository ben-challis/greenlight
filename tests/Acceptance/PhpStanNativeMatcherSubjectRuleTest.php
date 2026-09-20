<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\RequiresResource;
use Greenlight\Attribute\Test;
use Greenlight\Sandbox\TemporaryDirectory;
use Greenlight\Tests\Support\PhpStanProbe;

use function Greenlight\expect;

#[RequiresResource('analysis-process')]
final readonly class PhpStanNativeMatcherSubjectRuleTest
{
    public function __construct(private TemporaryDirectory $tempDirectory) {}

    #[Test]
    public function nativeMatcherSubjectTypesFollowExpectationChains(): void
    {
        expect('greenlight')
            ->toContain(...['light'])
            ->toContain(...['needle' => 'light']);

        $probe = PhpStanProbe::analyze(
            $this->tempDirectory,
            <<<'PHP'
            <?php

            declare(strict_types=1);

            use function Greenlight\expect;

            function greenlightGoodNativeMatcherSubjectProbe(mixed $subject): void
            {
                expect('greenlight')->toContain('light');
                expect('greenlight')->toContain(...['light']);
                expect('greenlight')->toContain(...['needle' => 'light']);
                expect([1, 2])->toHaveCount(2);
                expect(new ArrayIterator())->toBeEmpty();
                expect('greenlight')->toHaveLength(10);
                expect(['status' => 'green'])->toHaveKey('status');
                expect(['status' => 'green'])->toContainSubset(['status' => 'green']);
                expect(2)->toBeGreaterThan(1);
                expect('greenlight')->toStartWith('green');
                expect($subject)->toEndWith('light');
                expect()->calling(static fn(): string => 'greenlight')->returnValue()->eventually()
                    ->within(1.0)
                    ->toMatch('/green/');
                expect()->calling(static fn(): int => 2)->returnValue()->consistently()
                    ->for(0.1)
                    ->toBeWithin(1.0, 2.0);
            }
            PHP,
            <<<'PHP'
            <?php

            declare(strict_types=1);

            use function Greenlight\expect;

            function greenlightBadNativeMatcherSubjectProbe(): void
            {
                expect(1)->toContain('1');
                expect('greenlight')->toContain(1);
                expect('greenlight')->toContain(...[1]);
                expect('greenlight')->toContain(...['needle' => []]);
                expect('greenlight')->toHaveCount(10);
                expect(1)->toBeEmpty();
                expect(1)->toHaveLength(1);
                expect('greenlight')->toHaveKey(0);
                expect('greenlight')->toContainSubset([]);
                expect('1')->toBeGreaterThan(0);
                expect('1')->toBeGreaterThanOrEqual(0);
                expect('1')->toBeLessThan(2);
                expect('1')->toBeLessThanOrEqual(2);
                expect('1')->toBeWithin(1.0, 1.0);
                expect()->calling(static fn(): int => 1)->returnValue()->eventually()
                    ->within(1.0)
                    ->toMatch('/1/');
                expect(1)->toStartWith('1');
                expect(1)->toEndWith('1');
                expect([])->toBeJson();
                expect()->calling(static fn(): array => [])->returnValue()->consistently()
                    ->for(0.1)
                    ->toMatchJson('{}');
            }
            PHP,
        );

        expect($probe->exitCode)->because('native matchers require compatible subject types')->toBe(1);
        expect($probe->goodPassed)->toBeTrue();
        expect(\count($probe->errors))->toBe(19);
        expect($probe->messages())
            ->toContain('toContain() requires a string or iterable subject')
            ->toContain('toContain() requires a string needle for a string subject')
            ->toContain('toMatchJson() requires a string subject');
    }
}
