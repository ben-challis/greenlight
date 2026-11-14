<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\RequiresResource;
use Greenlight\Attribute\Test;
use Greenlight\Sandbox\TemporaryDirectory;
use Greenlight\Tests\Support\PhpStanProbe;

use function Greenlight\expect;

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

        expect($probe->exitCode)->because('mock plans must satisfy their doubled methods')->toBe(1);
        expect($probe->goodPassed)->toBeTrue();
        expect(\count($probe->errors))->toBe(8);
        expect($probe->messages())
            ->toContain('Mock plan method Greenlight\\Tests\\Fixture\\Doubles\\Wide::missing() does not exist')
            ->toContain('withNoArguments() supplies 0 arguments')
            ->toContain('with() supplies 1 argument')
            ->toContain('parameter $limit has type string, but the parameter requires int')
            ->toContain('parameter $rest has type string, but the parameter requires int')
            ->toContain('matcher for Greenlight\\Tests\\Fixture\\Doubles\\Wide::unionType() accepts '
                . 'Countable&Greenlight\\Tests\\Fixture\\Doubles\\Marker, but parameter $value requires int|string')
            ->toContain('matcher for Greenlight\\Tests\\Fixture\\Doubles\\Wide::intersectionType() accepts int|string, '
                . 'but parameter $value requires Countable&Greenlight\\Tests\\Fixture\\Doubles\\Marker');
    }

    #[Test]
    public function chainedPlanCallsKeepTheSelectedMethod(): void
    {
        $probe = PhpStanProbe::analyze(
            $this->tempDirectory,
            <<<'PHP'
            <?php

            declare(strict_types=1);

            use Greenlight\Doubles\Doubles;
            use Greenlight\Doubles\MockPlan;

            interface ChainedValidPlan
            {
                public function find(int $id, string $name): int;
            }

            function acceptChainedString(string $value): void {}

            function greenlightChainedValidPlanProbe(Doubles $doubles): void
            {
                $doubles->mock(ChainedValidPlan::class, static function (MockPlan $plan): void {
                    $plan->expects('find')->once()->with(1, 'a')->andReturns(1);
                    $captor = $plan->expects('find')->times(2)->andReturns(2)->captureArgument(1);
                    acceptChainedString($captor->value());
                });
            }
            PHP,
            <<<'PHP'
            <?php

            declare(strict_types=1);

            use Greenlight\Doubles\Doubles;
            use Greenlight\Doubles\MockPlan;

            interface ChainedInvalidPlan
            {
                public function find(int $id, string $name): int;
            }

            function acceptChainedInt(int $value): void {}

            function greenlightChainedInvalidPlanProbe(Doubles $doubles): void
            {
                $doubles->mock(ChainedInvalidPlan::class, static function (MockPlan $plan): void {
                    $plan->expects('find')->once()->andReturns('wrong');
                    $plan->expects('find')->never()->with('wrong', 'a');
                    $plan->expects('find')->atLeast(1)->captureArgument(2);
                    $captor = $plan->expects('find')->once()->andReturns(1)->captureArgument(1);
                    acceptChainedInt($captor->value());
                });
            }
            PHP,
        );

        expect($probe->exitCode)
            ->because('PHPStan checks each call in a fluent mock plan chain')
            ->toBe(1);
        expect($probe->goodPassed)
            ->because('PHPStan messages: ' . $probe->messages())
            ->toBeTrue();
        expect(\count($probe->errors))
            ->because('PHPStan messages: ' . $probe->messages())
            ->toBe(4);
        expect($probe->messages())
            ->toContain('andReturns() value #1 for ChainedInvalidPlan::find() has type string, but the method returns int')
            ->toContain('parameter $id has type string, but the parameter requires int')
            ->toContain('captureArgument(2) for ChainedInvalidPlan::find() requires a position from zero to 1')
            ->toContain('expects int, string given');
    }
}
