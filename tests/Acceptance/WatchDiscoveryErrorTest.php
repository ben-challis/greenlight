<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Sandbox\TemporaryDirectory;
use Greenlight\Test\Cleanup;
use Greenlight\Tests\Support\AcceptanceProject;
use Greenlight\Tests\Support\GreenlightCli;

final readonly class WatchDiscoveryErrorTest
{
    public function __construct(
        private TemporaryDirectory $tempDirectory,
        private Cleanup $cleanup,
    ) {}

    #[Test]
    public function watchReportsDiscoveryErrorsAndKeepsWaiting(): void
    {
        $project = AcceptanceProject::create($this->tempDirectory, 'watch-discovery-error');
        $project->writeFile('tests/WatchProbeTest.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace WatchDiscoveryError;

            use Greenlight\Attribute\Test;

            final class WatchProbeTest
            {
                #[Test]
                public function passes(): void {}
            }
            PHP);
        $project->configureWithTestFiles(['tests/WatchProbeTest.php'], workers: 1);
        $process = GreenlightCli::start(
            $project->directory,
            ['run', '--watch', '--reporter=plain'],
        );
        $this->cleanup->defer($process->terminate(...));

        $output = $process->readStdoutUntil('Waiting for changes', 20.0);

        Expect::that($output)
            ->because('watch mode MUST complete its initial valid run')
            ->toContain('1 test, 1 passed');

        $project->writeFile('tests/BrokenTest.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace WatchDiscoveryError;

            final class WrongName {}
            PHP);
        $output = $process->readStdoutUntil('Waiting for changes', 20.0);

        Expect::that($output)
            ->because('watch mode MUST continue after a discovery error')
            ->toContain('Detected changes');

        $process->write('q');
        $result = $process->wait(10.0);

        Expect::that($result->exitCode)
            ->because('watch mode MUST remain available after a discovery error')
            ->toBe(0);
        Expect::that($result->stderr)
            ->because('watch mode MUST report the discovery error')
            ->toContain('BrokenTest.php')
            ->toContain('WrongName');
    }
}
