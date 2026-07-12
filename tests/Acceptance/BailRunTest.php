<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Tests\Support\AcceptanceProject;

/**
 * Drives --bail through the real CLI against a project whose first class
 * fails every test, so the run stops before reaching the later classes.
 *
 * The CLI prints no "stopped after N failures" line; the only observable
 * signs of an early stop are the exit code and a final summary that covers
 * fewer tests than the six-test plan the run header announces.
 */
final class BailRunTest
{
    #[Test]
    public function bailWithNoValueStopsAfterOneFailure(): void
    {
        $project = $this->writeProject();

        try {
            [$exit, $output] = $this->run($project, '--bail');

            Expect::that($exit)->toBe(1)
                ->and($output)->toContain('6 tests, 1 worker')
                ->and($output)->toContain('1 test, 0 passed, 1 errored')
                ->and($output)->not()->toContain('BProbe')
                ->and($output)->not()->toContain('CProbe');
        } finally {
            $project->remove();
        }
    }

    #[Test]
    public function bailWithAnExplicitCountStopsAfterThatManyFailures(): void
    {
        $project = $this->writeProject();

        try {
            [$exit, $output] = $this->run($project, '--bail=2');

            // Both counted failures come from class A, so neither later
            // class starts.
            Expect::that($exit)->toBe(1)
                ->and($output)->toContain('6 tests, 1 worker')
                ->and($output)->toContain('2 tests, 0 passed, 2 errored')
                ->and($output)->not()->toContain('BProbe')
                ->and($output)->not()->toContain('CProbe');
        } finally {
            $project->remove();
        }
    }

    #[Test]
    public function withoutBailTheWholePlanRuns(): void
    {
        $project = $this->writeProject();

        try {
            [$exit, $output] = $this->run($project);

            Expect::that($exit)->toBe(1)
                ->and($output)->toContain('6 tests, 3 passed, 3 errored');
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
        $project = AcceptanceProject::create('bail');

        $project->write('tests/BailAProbeTest.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace BailProbe;

            use Greenlight\Attribute\Test;

            final class BailAProbeTest
            {
                #[Test]
                public function one(): never
                {
                    throw new \RuntimeException('bail probe failure a1');
                }

                #[Test]
                public function two(): never
                {
                    throw new \RuntimeException('bail probe failure a2');
                }
            }
            PHP);

        $project->write('tests/BailBProbeTest.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace BailProbe;

            use Greenlight\Attribute\Test;

            final class BailBProbeTest
            {
                #[Test]
                public function one(): never
                {
                    throw new \RuntimeException('bail probe failure b1');
                }

                #[Test]
                public function two(): void {}
            }
            PHP);

        $project->write('tests/BailCProbeTest.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace BailProbe;

            use Greenlight\Attribute\Test;

            final class BailCProbeTest
            {
                #[Test]
                public function one(): void {}

                #[Test]
                public function two(): void {}
            }
            PHP);

        $project->writeConfig([
            'tests/BailAProbeTest.php',
            'tests/BailBProbeTest.php',
            'tests/BailCProbeTest.php',
        ]);

        return $project;
    }
}
