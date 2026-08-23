<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Sandbox\TemporaryDirectory;
use Greenlight\Tests\Support\AcceptanceProject;
use Greenlight\Tests\Support\GreenlightCli;
use Greenlight\Tests\Support\JsonlEvents;

final readonly class CustomTestPlanTest
{
    public function __construct(private TemporaryDirectory $tempDirectory) {}

    #[Test]
    public function configuredTransformerCanSelectTestsBeforeExecution(): void
    {
        $project = $this->project('custom-test-plan', <<<'PHP'
            final readonly class BetaOnlyPlan implements TestPlanTransformer
            {
                public function transformTestPlan(TestPlan $plan): TestPlan
                {
                    return $plan->withTests(array_values(array_filter(
                        $plan->tests,
                        static fn(TestId $test): bool => $test->class === 'CustomTestPlanProbe\\BetaTest',
                    )));
                }
            }

            return GreenlightConfig::create()
                ->paths([__DIR__ . '/tests'])
                ->workers(1)
                ->plugins(static fn(): BetaOnlyPlan => new BetaOnlyPlan());
            PHP);

        $result = GreenlightCli::run($project->directory, ['run', '--reporter=jsonl']);

        Expect::that($result->exitCode)->toBe(0);
        Expect::that(JsonlEvents::finishedTestIds($result))->toBe([
            'CustomTestPlanProbe\\BetaTest::passes',
        ]);
    }

    #[Test]
    public function configuredTransformerCannotAddAnUnknownTest(): void
    {
        $project = $this->project('invalid-test-plan', <<<'PHP'
            final readonly class InvalidPlan implements TestPlanTransformer
            {
                public function transformTestPlan(TestPlan $plan): TestPlan
                {
                    return $plan->withTests([new TestId('UnknownTest', 'passes')]);
                }
            }

            return GreenlightConfig::create()
                ->paths([__DIR__ . '/tests'])
                ->workers(1)
                ->plugins(static fn(): InvalidPlan => new InvalidPlan());
            PHP);

        $result = GreenlightCli::run($project->directory, ['run', '--reporter=jsonl', '--no-ansi']);

        Expect::that($result->exitCode)->toBe(1);
        Expect::that($result->stderr)->toBe(
            'greenlight: Plugin "InvalidPlan" added unknown test "UnknownTest::passes" during transformTestPlan(). A plan transformer MAY only remove or reorder selected tests.',
        );
    }

    private function project(string $name, string $configuration): AcceptanceProject
    {
        $project = AcceptanceProject::create($this->tempDirectory, $name);
        $test = <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace CustomTestPlanProbe;

            use Greenlight\Attribute\Test;

            final class %s
            {
                #[Test]
                public function passes(): void {}
            }

            PHP;
        $project->writeFile('tests/AlphaTest.php', \sprintf($test, 'AlphaTest'));
        $project->writeFile('tests/BetaTest.php', \sprintf($test, 'BetaTest'));
        $project->writeFile('greenlight.php', \sprintf(
            <<<'PHP'
            <?php

            declare(strict_types=1);

            use Greenlight\Config\GreenlightConfig;
            use Greenlight\Plugin\TestPlan;
            use Greenlight\Plugin\TestPlanTransformer;
            use Greenlight\Test\TestId;

            require_once __DIR__ . '/tests/AlphaTest.php';
            require_once __DIR__ . '/tests/BetaTest.php';

            %s

            PHP,
            $configuration,
        ));

        return $project;
    }
}
