<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Cli;

use Greenlight\Attribute\Test;
use Greenlight\Cli\Application;
use Greenlight\Coverage\Relay\SubprocessCoverage;
use Greenlight\Expect\Expect;
use Greenlight\Reporting\ReportGenerationFailed;
use Greenlight\Sandbox\EnvironmentVariables;
use Greenlight\Sandbox\TemporaryDirectory;
use Greenlight\Test\Cleanup;
use Greenlight\Tests\Support\AcceptanceProject;
use Greenlight\Tests\Support\FixturePath;
use Greenlight\Tests\Support\MemoryStream;

final readonly class ApplicationCoverageCleanupTest
{
    public function __construct(
        private TemporaryDirectory $tempDirectory,
        private EnvironmentVariables $environment,
        private Cleanup $cleanup,
    ) {}

    #[Test]
    public function runEventFailuresRestoreTheCoverageRelayEnvironment(): void
    {
        $this->environment->unset(SubprocessCoverage::DIRECTORY_ENV);
        $this->environment->unset(SubprocessCoverage::INCLUDE_ENV);
        $fixtureDirectory = FixturePath::get('DiscoveryBasic');
        $project = AcceptanceProject::create($this->tempDirectory, 'application-coverage-cleanup');
        $project->writeFile('greenlight.php', \sprintf(
            <<<'PHP'
            <?php

            declare(strict_types=1);

            use Greenlight\Config\GreenlightConfig;
            use Greenlight\Event\Event;
            use Greenlight\Plugin\RunLifecycleSubscriber;
            use Greenlight\Reporting\ReportGenerationFailed;

            final class FailingRunSubscriber implements RunLifecycleSubscriber
            {
                #[\Override]
                public function onRunEvent(Event $event): never
                {
                    throw ReportGenerationFailed::writeFailed();
                }
            }

            return GreenlightConfig::create()
                ->paths([%s])
                ->workers(1)
                ->coverage(fn($coverage) => $coverage->include(%s))
                ->plugins(
                    static fn(): FailingRunSubscriber => new FailingRunSubscriber(),
                );

            PHP,
            \var_export($fixtureDirectory, true),
            \var_export($fixtureDirectory, true),
        ));
        $stdout = MemoryStream::open();
        $this->cleanup->defer(static fn() => MemoryStream::close($stdout));
        $stderr = MemoryStream::open();
        $this->cleanup->defer(static fn() => MemoryStream::close($stderr));

        Expect::that(fn() => Application::forStreams($stdout, $stderr)->run(
            ['run', '--reporter=plain', '--no-ansi'],
            $project->directory,
        ))
            ->because('a run event failure MUST propagate after coverage cleanup')
            ->toThrow(ReportGenerationFailed::class);

        Expect::that(\getenv(SubprocessCoverage::DIRECTORY_ENV))
            ->because('a failed run MUST restore an absent coverage relay directory')
            ->toBeFalse();
        Expect::that(\getenv(SubprocessCoverage::INCLUDE_ENV))
            ->because('a failed run MUST restore absent coverage include paths')
            ->toBeFalse();
    }
}
