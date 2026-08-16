<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Fixture\TempDirectory;
use Greenlight\Tests\Support\AcceptanceProject;
use Greenlight\Tests\Support\GreenlightCli;
use Greenlight\Tests\Support\ProcessResult;

final readonly class BailRunTest
{
    public function __construct(private TempDirectory $tempDirectory) {}

    #[Test]
    public function bailWithNoValueStopsAfterOneFailure(): void
    {
        $project = $this->writeProject();
        $result = $this->run($project, '--bail');
        Expect::that($result->exitCode)->because('bail with no value stops after one failure')->toBe(1);
        Expect::that($result->output())->toContain('6 tests, 1 worker')
            ->toContain('1 test, 0 passed, 1 errored')
            ->not()->toContain('BProbe')
            ->not()->toContain('CProbe');
    }

    #[Test]
    public function bailWithAnExplicitCountStopsAfterThatManyFailures(): void
    {
        $project = $this->writeProject();
        $result = $this->run($project, '--bail=2');
        // Class A causes both counted failures. Thus, later classes do not
        // start.
        Expect::that($result->exitCode)->because('bail with an explicit count stops after that many failures')->toBe(1);
        Expect::that($result->output())->toContain('6 tests, 1 worker')
            ->toContain('2 tests, 0 passed, 2 errored')
            ->not()->toContain('BProbe')
            ->not()->toContain('CProbe');
    }

    #[Test]
    public function withoutBailTheWholePlanRuns(): void
    {
        $project = $this->writeProject();
        $result = $this->run($project);
        Expect::that($result->exitCode)->because('without bail the whole plan runs')->toBe(1);
        Expect::that($result->output())->toContain('6 tests, 3 passed, 3 errored');
    }

    private function run(AcceptanceProject $project, string ...$flags): ProcessResult
    {
        return GreenlightCli::run($project->directory, \array_values(['run', '--reporter=plain', ...$flags]));
    }

    private function writeProject(): AcceptanceProject
    {
        $project = AcceptanceProject::create($this->tempDirectory, 'bail');

        $project->writeFile('tests/BailAProbeTest.php', <<<'PHP'
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

        $project->writeFile('tests/BailBProbeTest.php', <<<'PHP'
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

        $project->writeFile('tests/BailCProbeTest.php', <<<'PHP'
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

        $project->configureWithTestFiles([
            'tests/BailAProbeTest.php',
            'tests/BailBProbeTest.php',
            'tests/BailCProbeTest.php',
        ]);

        return $project;
    }
}
