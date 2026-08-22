<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\RequiresResource;
use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Sandbox\TemporaryDirectory;
use Greenlight\Tests\Support\PhpStanProbe;

#[RequiresResource('analysis-process')]
final readonly class PhpStanDoublePlanRuleTest
{
    public function __construct(private TemporaryDirectory $tempDirectory) {}

    #[Test]
    public function mockPlanArgumentsMustSatisfyTheDoubledMethod(): void
    {
        $probe = PhpStanProbe::analyze(
            $this->tempDirectory,
            <<<'PHP'
            <?php

            declare(strict_types=1);

            use Greenlight\Doubles\Argument;
            use Greenlight\Doubles\Doubles;
            use Greenlight\Doubles\MockPlan;
            use Greenlight\Tests\Fixture\Doubles\Marker;
            use Greenlight\Tests\Fixture\Doubles\Wide;

            /** @param non-empty-string $method */
            function greenlightGoodDoublePlanProbe(Doubles $doubles, string $method): void
            {
                $doubles->mock(Wide::class, static function (MockPlan $plan) use ($method): void {
                    $plan->expects('withDefaults')->withNoArguments();
                    $plan->expects('unionType')->with(1);
                    $plan->expects('unionType')->with(Argument::any());
                    $plan->expects('unionType')->with(Argument::union('int', 'string'));
                    $plan->expects('intersectionType')->with(Argument::intersection(Marker::class, Countable::class));
                    $plan->expects('variadic')->with('head', 1, 2);
                    $plan->expects($method)->withNoArguments();
                });
            }
            PHP,
            <<<'PHP'
            <?php

            declare(strict_types=1);

            use Greenlight\Doubles\Argument;
            use Greenlight\Doubles\Doubles;
            use Greenlight\Doubles\MockPlan;
            use Greenlight\Tests\Fixture\Doubles\Marker;
            use Greenlight\Tests\Fixture\Doubles\Wide;

            function greenlightBadDoublePlanProbe(Doubles $doubles): void
            {
                $doubles->mock(Wide::class, static function (MockPlan $plan): void {
                    $plan->expects('missing');
                    $plan->expects('unionType')->withNoArguments();
                    $plan->expects('returnsVoid')->with(1);
                    $plan->expects('withDefaults')->with('eleven');
                    $plan->expects('variadic')->with('head', 'not-an-int');
                    $plan->expects('withDefaults')->with();
                    $plan->expects('unionType')->with(Argument::intersection(Marker::class, Countable::class));
                    $plan->expects('intersectionType')->with(Argument::union('int', 'string'));
                });
            }
            PHP,
        );

        Expect::that($probe->exitCode)->because('mock plans must satisfy their doubled methods')->toBe(1);
        Expect::that($probe->goodPassed)->toBeTrue();
        Expect::that(\count($probe->errors))->toBe(8);
        Expect::that($probe->messages())
            ->toContain('Mock plan method Greenlight\\Tests\\Fixture\\Doubles\\Wide::missing() does not exist');
        Expect::that($probe->messages())->toContain('withNoArguments() supplies 0 arguments');
        Expect::that($probe->messages())->toContain('with() supplies 1 argument');
        Expect::that($probe->messages())->toContain('parameter $limit has type string, but the parameter requires int');
        Expect::that($probe->messages())->toContain('parameter $rest has type string, but the parameter requires int');
        Expect::that($probe->messages())
            ->toContain('matcher for Greenlight\\Tests\\Fixture\\Doubles\\Wide::unionType() accepts '
                . 'Countable&Greenlight\\Tests\\Fixture\\Doubles\\Marker, but parameter $value requires int|string');
        Expect::that($probe->messages())
            ->toContain('matcher for Greenlight\\Tests\\Fixture\\Doubles\\Wide::intersectionType() accepts int|string, '
                . 'but parameter $value requires Countable&Greenlight\\Tests\\Fixture\\Doubles\\Marker');
    }
}
