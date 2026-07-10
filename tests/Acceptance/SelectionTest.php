<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Tests\Support\AcceptanceProject;

/**
 * Drives --filter and --failed through the real CLI against a throwaway
 * project in a unique temp directory, so the run state keyed by that
 * directory cannot collide with other tests.
 */
final class SelectionTest
{
    #[Test]
    public function filterSelectsByMethodClassAndWildcard(): void
    {
        $project = $this->writeProject();

        try {
            [$exit, $output] = $this->run($project, '--filter=alwaysPasses');
            Expect::that($exit)->toBe(0)->and($output)->toContain('1 test, 1 passed');

            [$exit, $output] = $this->run($project, '--filter=SelectionProbeTest');
            Expect::that($output)->toContain('3 tests,');

            [$exit, $output] = $this->run($project, '--filter=*::breaks?ometimes');
            Expect::that($exit)->toBe(1)->and($output)->toContain('1 test, 0 passed, 1 errored');

            [$exit, $output] = $this->run($project, '--filter=nothingMatchesThis');
            Expect::that($exit)->toBe(1)->and($output)->toContain('No tests found');
        } finally {
            $project->remove();
        }
    }

    #[Test]
    public function failedRerunsExactlyThePreviousFailures(): void
    {
        $project = $this->writeProject();

        try {
            // --failed before any run is a usage error.
            [$exit, $output] = $this->run($project, '--failed');
            Expect::that($exit)->toBe(64)->and($output)->toContain('previous run');

            // A full run records one failure.
            [$exit] = $this->run($project);
            Expect::that($exit)->toBe(1);

            // --failed re-runs exactly that one test.
            [$exit, $output] = $this->run($project, '--failed');
            Expect::that($exit)->toBe(1)
                ->and($output)->toContain('1 test, 0 passed, 1 errored')
                ->and($output)->toContain('breaksSometimes');

            // A run where everything passes empties the state.
            [$exit] = $this->run($project, '--filter=alwaysPasses');
            Expect::that($exit)->toBe(0);

            [$exit, $output] = $this->run($project, '--failed');
            Expect::that($exit)->toBe(0)->and($output)->toContain('Nothing failed');
        } finally {
            $project->remove();
        }
    }

    /**
     * @return array{int, string}
     */
    private function run(AcceptanceProject $project, string ...$flags): array
    {
        return $project->run('run', '--reporter=plain', ...$flags);
    }

    private function writeProject(): AcceptanceProject
    {
        $project = AcceptanceProject::create('selection');

        $project->write('tests/SelectionProbeTest.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace SelectionProbe;

            use Greenlight\Attribute\Test;

            final class SelectionProbeTest
            {
                #[Test]
                public function alwaysPasses(): void {}

                #[Test]
                public function alsoPasses(): void {}

                #[Test]
                public function breaksSometimes(): never
                {
                    throw new \RuntimeException('intentional selection failure');
                }
            }
            PHP);

        $project->write('greenlight.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            use Greenlight\Config\GreenlightConfig;

            require_once __DIR__ . '/tests/SelectionProbeTest.php';

            return GreenlightConfig::create()
                ->paths([__DIR__ . '/tests'])
                ->workers(1);
            PHP);

        return $project;
    }
}
