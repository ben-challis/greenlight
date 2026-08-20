<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Cli;

use Greenlight\Attribute\Isolated;
use Greenlight\Attribute\Test;
use Greenlight\Cli\Application;
use Greenlight\Core\ErrorTrap;
use Greenlight\Expect\Expect;
use Greenlight\Fixture\EnvironmentSandbox;
use Greenlight\Fixture\TempDirectory;
use Greenlight\Tests\Support\AcceptanceProject;
use Greenlight\Tests\Support\FilesystemRestriction;
use Greenlight\Tests\Support\MemoryStream;

final readonly class ApplicationWorkerBinPathTest
{
    public function __construct(
        private TempDirectory $tempDirectory,
        private EnvironmentSandbox $environment,
    ) {}

    #[Test]
    #[Isolated]
    public function inaccessibleWorkerBinaryFallsBackWithoutEngineDiagnostics(): void
    {
        $this->environment->unset('GREENLIGHT_CHANNEL');
        $project = AcceptanceProject::createWithDiscoveryBasicTests(
            $this->tempDirectory,
            'application-worker-bin-path',
        );
        $stdout = MemoryStream::open();
        $stderr = MemoryStream::open();

        $root = \dirname(__DIR__, 3);
        $restrictedBin = \dirname($root);
        FilesystemRestriction::toProject($root);

        try {
            $exit = ErrorTrap::run(
                static fn() => Application::forStreams($stdout, $stderr)->run(
                    ['run', '--workers=2', '--reporter=plain', '--no-ansi'],
                    $project->directory,
                    $restrictedBin,
                ),
                $warning,
            );
            \rewind($stderr);
            $errors = \stream_get_contents($stderr);
        } finally {
            MemoryStream::close($stdout, $stderr);
        }

        Expect::that($exit)
            ->because('an inaccessible worker binary MUST use the in-process fallback')
            ->toBe(0);
        Expect::that($warning)
            ->because('an inaccessible worker binary MUST not leak an engine diagnostic')
            ->toBeNull();
        Expect::that($errors)->toBe('');
    }
}
