<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Cli;

use Greenlight\Attribute\Test;
use Greenlight\Cli\Application;
use Greenlight\Expect\Expect;
use Greenlight\Expect\Fail;
use Greenlight\Fixture\EnvironmentSandbox;
use Greenlight\Fixture\TempDirectory;
use Greenlight\Tests\Support\AcceptanceProject;

final readonly class ApplicationReporterStreamTest
{
    public function __construct(
        private TempDirectory $tempDirectory,
        private EnvironmentSandbox $environment,
    ) {}

    #[Test]
    public function runReportersUseTheConfiguredOutputStream(): void
    {
        $this->environment->unset('GREENLIGHT_CHANNEL');
        $project = AcceptanceProject::createWithDiscoveryBasicTests(
            $this->tempDirectory,
            'application-reporter-stream',
        );
        $stdout = \fopen('php://memory', 'w+');
        $stderr = \fopen('php://memory', 'w+');

        if ($stdout === false || $stderr === false) {
            Fail::because('Greenlight did not open the CLI test streams.');
        }

        try {
            $exit = Application::forStreams($stdout, $stderr)->run(
                ['run', '--workers=1', '--reporter=plain', '--no-ansi'],
                $project->directory,
            );
            \rewind($stdout);
            \rewind($stderr);
            $output = \stream_get_contents($stdout);
            $errors = \stream_get_contents($stderr);
        } finally {
            \fclose($stdout);
            \fclose($stderr);
        }

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
