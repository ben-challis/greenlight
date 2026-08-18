<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Cli;

use Greenlight\Attribute\Test;
use Greenlight\Cli\Application;
use Greenlight\Expect\Expect;
use Greenlight\Expect\Fail;
use Greenlight\Fixture\EnvironmentSandbox;
use Greenlight\Fixture\TempDirectory;
use Greenlight\Reporting\ReportingError;
use Greenlight\Runner\SubprocessCoverage;
use Greenlight\Tests\Support\AcceptanceProject;

final readonly class ApplicationCoverageCleanupTest
{
    public function __construct(
        private TempDirectory $tempDirectory,
        private EnvironmentSandbox $environment,
    ) {}

    #[Test]
    public function runEventFailuresRestoreTheCoverageRelayEnvironment(): void
    {
        $this->environment->unset(SubprocessCoverage::DIRECTORY_ENV);
        $this->environment->unset(SubprocessCoverage::INCLUDE_ENV);
        $fixtureDirectory = \dirname(__DIR__, 2) . '/Fixture/DiscoveryBasic';
        $project = AcceptanceProject::create($this->tempDirectory, 'application-coverage-cleanup');
        $project->writeFile('greenlight.php', \sprintf(
            <<<'PHP'
            <?php

            declare(strict_types=1);

            use Greenlight\Config\GreenlightConfig;
            use Greenlight\Core\Event\Event;
            use Greenlight\Plugin\RunLifecycleSubscriber;
            use Greenlight\Reporting\ReportingError;

            $failure = new class implements RunLifecycleSubscriber {
                #[\Override]
                public function onRunEvent(Event $event): never
                {
                    throw ReportingError::writeFailed();
                }
            };

            return GreenlightConfig::create()
                ->paths([%s])
                ->workers(1)
                ->coverage(fn($coverage) => $coverage->include(%s))
                ->plugins($failure);

            PHP,
            \var_export($fixtureDirectory, true),
            \var_export($fixtureDirectory, true),
        ));
        $stdout = \fopen('php://memory', 'w');
        $stderr = \fopen('php://memory', 'w');

        if ($stdout === false || $stderr === false) {
            Fail::because('Greenlight did not open the CLI test streams.');
        }

        try {
            Expect::that(fn() => Application::forStreams($stdout, $stderr)->run(
                ['run', '--reporter=plain', '--no-ansi'],
                $project->directory,
            ))
                ->because('a run event failure MUST propagate after coverage cleanup')
                ->toThrow(ReportingError::class);
        } finally {
            \fclose($stdout);
            \fclose($stderr);
        }

        Expect::that(\getenv(SubprocessCoverage::DIRECTORY_ENV))
            ->because('a failed run MUST restore an absent coverage relay directory')
            ->toBeFalse();
        Expect::that(\getenv(SubprocessCoverage::INCLUDE_ENV))
            ->because('a failed run MUST restore absent coverage include paths')
            ->toBeFalse();
    }
}
