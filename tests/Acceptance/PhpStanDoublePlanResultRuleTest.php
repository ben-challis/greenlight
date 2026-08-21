<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\RequiresResource;
use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Sandbox\TemporaryDirectory;
use Greenlight\Tests\Support\PhpStanProbe;

#[RequiresResource('analysis-process')]
final readonly class PhpStanDoublePlanResultRuleTest
{
    public function __construct(private TemporaryDirectory $tempDirectory) {}

    #[Test]
    public function mockPlanResultsAndLimitsFollowTheSelectedMethod(): void
    {
        $probe = PhpStanProbe::analyze(
            $this->tempDirectory,
            <<<'PHP'
            <?php

            declare(strict_types=1);

            use Greenlight\Doubles\Doubles;
            use Greenlight\Doubles\MockPlan;

            interface ValidResultPlan
            {
                public function add(int $left, int $right): int;

                /** @return list<int> */
                public function collect(string $name, int ...$values): array;
            }

            function greenlightValidResultPlanProbe(Doubles $doubles): void
            {
                $doubles->mock(ValidResultPlan::class, static function (MockPlan $plan): void {
                    $plan->expects('add')->times(0)->andReturns(1);
                    $plan->expects('add')->atLeast(1)->andReturnsSequence(1, 2);
                    $plan->expects('add')->andReturnsUsing(static fn(int $left, int $right): int => $left + $right);
                    $plan->expects('add')->captureArgument(1);
                    $plan->expects('collect')->andReturns([])->captureArgument(99);
                });
            }
            PHP,
            <<<'PHP'
            <?php

            declare(strict_types=1);

            use Greenlight\Doubles\Doubles;
            use Greenlight\Doubles\MockPlan;

            interface InvalidResultPlan
            {
                public function add(int $left, int $right): int;

                public function ping(): void;
            }

            function greenlightInvalidResultPlanProbe(Doubles $doubles): void
            {
                $doubles->mock(InvalidResultPlan::class, static function (MockPlan $plan): void {
                    $plan->expects('add')->times(-1);
                    $plan->expects('add')->atLeast(0);
                    $plan->expects('add')->andReturnsSequence();
                    $plan->expects('add')->andReturns('wrong');
                    $plan->expects('add')->andReturnsSequence(1, 'wrong');
                    $plan->expects('add')->andReturnsUsing(static fn(string $value): string => $value);
                    $plan->expects('add')->captureArgument(-1);
                    $plan->expects('add')->captureArgument(2);
                    $plan->expects('ping')->captureArgument();
                });
            }
            PHP,
        );

        Expect::that($probe->exitCode)
            ->because('PHPStan rejects invalid mock plan results and limits')
            ->toBe(1);
        Expect::that($probe->goodPassed)
            ->because('PHPStan messages: ' . $probe->messages())
            ->toBeTrue();
        Expect::that(\count($probe->errors))->toBe(9);
        Expect::that($probe->messages())->toContain('times(-1) requires a count of zero or more');
        Expect::that($probe->messages())->toContain('atLeast(0) requires a count of one or more');
        Expect::that($probe->messages())->toContain('andReturnsSequence() on InvalidResultPlan::add() requires at least one value');
        Expect::that($probe->messages())->toContain('andReturns() value #1 for InvalidResultPlan::add() has type string, but the method returns int');
        Expect::that($probe->messages())->toContain('andReturnsUsing() answer for InvalidResultPlan::add()');
        Expect::that($probe->messages())->toContain('captureArgument(2) for InvalidResultPlan::add() requires a position from zero to 1');
    }
}
