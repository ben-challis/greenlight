<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Cli;

use Greenlight\Attribute\Test;
use Greenlight\Cli\Application;
use Greenlight\Core\Test\Cleanup;
use Greenlight\Expect\Expect;
use Greenlight\Fixture\EnvironmentSandbox;
use Greenlight\Fixture\TempDirectory;
use Greenlight\Tests\Support\AcceptanceProject;
use Greenlight\Tests\Support\MemoryStream;

final readonly class ApplicationReporterStreamTest
{
    public function __construct(
        private TempDirectory $tempDirectory,
        private EnvironmentSandbox $environment,
        private Cleanup $cleanup,
    ) {}

    #[Test]
    public function runReportersUseTheConfiguredOutputStream(): void
    {
        $this->environment->unset('GREENLIGHT_CHANNEL');
        $project = AcceptanceProject::createWithDiscoveryBasicTests(
            $this->tempDirectory,
            'application-reporter-stream',
        );
        $stdout = MemoryStream::open();
        $this->cleanup->defer(static fn() => MemoryStream::close($stdout));
        $stderr = MemoryStream::open();
        $this->cleanup->defer(static fn() => MemoryStream::close($stderr));

        $exit = Application::forStreams($stdout, $stderr)->run(
            ['run', '--workers=1', '--reporter=plain', '--no-ansi'],
            $project->directory,
        );
        \rewind($stdout);
        \rewind($stderr);
        $output = \stream_get_contents($stdout);
        $errors = \stream_get_contents($stderr);

        Expect::that($exit)
            ->because('a run through configured streams MUST preserve the reporter exit code')
            ->toBe(0);
        Expect::that($output)
            ->because('the configured output stream MUST receive the complete run report')
            ->toContain('Greenlight dev-main')
            ->toContain('7 tests, 7 passed');
        Expect::that($errors)
            ->toBe('');
    }
}
