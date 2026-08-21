<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Sandbox\TemporaryDirectory;
use Greenlight\Tests\Support\AcceptanceProject;
use Greenlight\Tests\Support\GreenlightCli;

final readonly class WorkerRuntimeRunnerRunTest
{
    public function __construct(private TemporaryDirectory $tempDirectory) {}

    #[Test]
    public function runtimeBoundaryContainsAllPhysicalWorkerAssignments(): void
    {
        $project = AcceptanceProject::create($this->tempDirectory, 'worker-runtime-runner');
        $project->writeFile('tests/WorkerBoundaryTest.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace WorkerBoundaryProbe;

            use Greenlight\Attribute\Test;
            use Greenlight\Expect\Expect;
            use Greenlight\Plugin\WorkerRuntimeRunner;

            final class Boundary
            {
                public static bool $active = false;
            }

            final readonly class RuntimePlugin implements WorkerRuntimeRunner
            {
                public function __construct(private string $marker) {}

                public function runWorker(\Closure $worker): mixed
                {
                    Boundary::$active = true;

                    try {
                        return $worker();
                    } finally {
                        Boundary::$active = false;
                        file_put_contents($this->marker, 'closed');
                    }
                }
            }

            final readonly class WorkerBoundaryTest
            {
                #[Test]
                public function firstAssignmentRunsInsideTheBoundary(): void
                {
                    Expect::that(Boundary::$active)->toBeTrue();
                }

                #[Test]
                public function nextAssignmentUsesTheSameBoundary(): void
                {
                    Expect::that(Boundary::$active)->toBeTrue();
                }
            }
            PHP);
        $project->writeFile('greenlight.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            use Greenlight\Config\GreenlightConfig;
            use Greenlight\Plugin\PluginDefinition;
            use WorkerBoundaryProbe\RuntimePlugin;

            require_once __DIR__ . '/tests/WorkerBoundaryTest.php';

            return GreenlightConfig::create()
                ->paths([__DIR__ . '/tests'])
                ->workers(1)
                ->plugins(new PluginDefinition(
                    RuntimePlugin::class,
                    static fn(): RuntimePlugin => new RuntimePlugin(__DIR__ . '/worker-runtime.marker'),
                ));
            PHP);

        $result = GreenlightCli::run($project->directory, ['run', '--reporter=plain']);

        Expect::that($result->exitCode)
            ->because('worker runtime boundary run failed: ' . $result->output())
            ->toBe(0);
        Expect::that($result->output())->toContain('2 tests, 2 passed');
        Expect::that(\file_get_contents($project->directory . '/worker-runtime.marker'))->toBe('closed');
    }
}
