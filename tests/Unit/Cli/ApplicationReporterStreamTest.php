<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Cli;

use Greenlight\Attribute\Test;
use Greenlight\Cli\Application;
use Greenlight\Expect\Expect;
use Greenlight\Sandbox\EnvironmentVariables;
use Greenlight\Sandbox\TemporaryDirectory;
use Greenlight\Test\Cleanup;
use Greenlight\Tests\Support\AcceptanceProject;
use Greenlight\Tests\Support\MemoryStream;

final readonly class ApplicationReporterStreamTest
{
    public function __construct(
        private TemporaryDirectory $tempDirectory,
        private EnvironmentVariables $environment,
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

    #[Test]
    public function ansiFlagSelectsColoredAppendOnlyOutputWithoutATerminal(): void
    {
        $this->environment->unset('GREENLIGHT_CHANNEL');
        $this->environment->set('CI', 'true');
        $this->environment->unset('NO_COLOR');
        $project = AcceptanceProject::createWithDiscoveryBasicTests(
            $this->tempDirectory,
            'application-ansi-output',
        );
        $stdout = MemoryStream::open();
        $this->cleanup->defer(static fn() => MemoryStream::close($stdout));
        $stderr = MemoryStream::open();
        $this->cleanup->defer(static fn() => MemoryStream::close($stderr));

        $exit = Application::forStreams($stdout, $stderr)->run(
            ['run', '--workers=1', '--ansi'],
            $project->directory,
        );
        \rewind($stdout);
        \rewind($stderr);
        $output = \stream_get_contents($stdout);
        $errors = \stream_get_contents($stderr);

        Expect::that($exit)
            ->toBe(0);
        Expect::that($output)
            ->because('the ANSI flag uses color without cursor control')
            ->toContain("\x1b[32m")
            ->not()
            ->toContain("\x1b[0J");
        Expect::that($errors)
            ->toBe('');
    }
}
