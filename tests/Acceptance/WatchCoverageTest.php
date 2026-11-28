<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\Test;
use Greenlight\Sandbox\TemporaryDirectory;
use Greenlight\Test\Cleanup;
use Greenlight\Test\SkipTest;
use Greenlight\Tests\Support\AcceptanceProject;
use Greenlight\Tests\Support\GreenlightCli;

use function Greenlight\expect;

final readonly class WatchCoverageTest
{
    public function __construct(
        private TemporaryDirectory $tempDirectory,
        private Cleanup $cleanup,
    ) {}

    #[Test]
    public function watchIterationsDoNotCollectCoverage(): void
    {
        if (!\extension_loaded('xdebug')) {
            throw new SkipTest('The probe reads the Xdebug coverage state.');
        }

        $project = AcceptanceProject::create($this->tempDirectory, 'watch-coverage');
        $project->writeFile('tests/CoverageProbeTest.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace WatchCoverageProbe;

            use Greenlight\Attribute\Test;

            final class CoverageProbeTest
            {
                #[Test]
                public function probe(): void
                {
                    \file_put_contents(
                        \dirname(__DIR__) . '/coverage-state.txt',
                        \xdebug_code_coverage_started() ? 'started' : 'stopped',
                    );
                }
            }
            PHP);
        $project->writeFile('greenlight.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            use Greenlight\Config\CoverageBuilder;
            use Greenlight\Config\GreenlightConfig;

            require_once __DIR__ . '/tests/CoverageProbeTest.php';

            return GreenlightConfig::create()
                ->paths([__DIR__ . '/tests'])
                ->workers(1)
                ->coverage(static fn(CoverageBuilder $coverage) => $coverage->include(__DIR__ . '/tests'));
            PHP);

        $process = GreenlightCli::start(
            $project->directory,
            ['run', '--watch', '--reporter=plain'],
            ['XDEBUG_MODE' => 'coverage'],
        );
        $this->cleanup->defer($process->terminate(...));
        $process->readStdoutUntil('Waiting for changes', 20.0);
        $process->write('q');
        $result = $process->wait(10.0);

        expect($result->exitCode)->toBe(0);
        expect(\file_get_contents($project->path('coverage-state.txt')))
            ->because('watch mode writes no coverage, so an iteration MUST NOT collect it')
            ->toBe('stopped');
    }
}
