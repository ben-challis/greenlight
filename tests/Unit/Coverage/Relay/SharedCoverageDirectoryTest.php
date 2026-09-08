<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Coverage\Relay;

use Greenlight\Attribute\Test;
use Greenlight\Coverage\Collection\CoverageSettings;
use Greenlight\Coverage\CoverageError;
use Greenlight\Coverage\CoverageMap;
use Greenlight\Coverage\FileCoverage;
use Greenlight\Coverage\Relay\SharedCoverageDirectory;
use Greenlight\Coverage\Relay\SubprocessCoverage;
use Greenlight\Expect\Expect;
use Greenlight\Sandbox\EnvironmentVariables;
use Greenlight\Sandbox\TemporaryDirectory;
use Greenlight\Tests\Support\CoverageJson;

final readonly class SharedCoverageDirectoryTest
{
    public function __construct(
        private TemporaryDirectory $temporaryDirectory,
        private EnvironmentVariables $environment,
    ) {}

    #[Test]
    public function nestedSessionsKeepTheirCoverageAndRestoreTheirOwnParentEnvironment(): void
    {
        $this->environment->set(SubprocessCoverage::DIRECTORY_ENV, '/original/relay');
        $this->environment->set(SubprocessCoverage::INCLUDE_ENV, '/original/src');
        $outer = SharedCoverageDirectory::open(
            new CoverageSettings(['/outer/src']),
            $this->temporaryDirectory->path(),
        );
        $outerDirectory = $this->relayDirectory();
        CoverageJson::write(
            $outerDirectory . '/outer.json',
            new CoverageMap([new FileCoverage('/outer/src/A.php', [1], [2])]),
        );

        $inner = SharedCoverageDirectory::open(
            new CoverageSettings(['/inner/src']),
            $this->temporaryDirectory->path(),
        );
        $innerDirectory = $this->relayDirectory();
        CoverageJson::write(
            $innerDirectory . '/inner.json',
            new CoverageMap([new FileCoverage('/inner/src/B.php', [3], [4])]),
        );

        try {
            Expect::that($innerDirectory)->not()->toBe($outerDirectory);
            Expect::that(\getenv(SubprocessCoverage::INCLUDE_ENV))->toBe('/inner/src');
            Expect::that($inner->drain()?->toWire())->toBe([
                'files' => ['/inner/src/B.php' => [[3], [4]]],
            ]);
            Expect::that(\getenv(SubprocessCoverage::DIRECTORY_ENV))->toBe($outerDirectory);
            Expect::that(\getenv(SubprocessCoverage::INCLUDE_ENV))->toBe('/outer/src');
            Expect::that(\is_dir($innerDirectory))->toBeFalse();
            Expect::that(\is_file($outerDirectory . '/outer.json'))
                ->because('the inner session must preserve its parent coverage dump')
                ->toBeTrue();

            Expect::that($outer->drain()?->toWire())->toBe([
                'files' => ['/outer/src/A.php' => [[1], [2]]],
            ]);
            Expect::that(\getenv(SubprocessCoverage::DIRECTORY_ENV))->toBe('/original/relay');
            Expect::that(\getenv(SubprocessCoverage::INCLUDE_ENV))->toBe('/original/src');
            Expect::that(\is_dir($outerDirectory))->toBeFalse();
        } finally {
            $inner->drain();
            $outer->drain();
        }
    }

    #[Test]
    public function failedNestedSetupPreservesTheActiveRelayAndItsCoverage(): void
    {
        $this->environment->unset(SubprocessCoverage::DIRECTORY_ENV);
        $this->environment->unset(SubprocessCoverage::INCLUDE_ENV);
        $outer = SharedCoverageDirectory::open(
            new CoverageSettings(['/outer/src']),
            $this->temporaryDirectory->path(),
        );
        $outerDirectory = $this->relayDirectory();
        $dump = $outerDirectory . '/outer.json';
        CoverageJson::write($dump, new CoverageMap([new FileCoverage('/outer/src/A.php', [1], [2])]));

        try {
            Expect::that(static fn() => SharedCoverageDirectory::open(
                new CoverageSettings(['/inner/src']),
                $dump,
            ))->toThrow(CoverageError::class, matching: '/Failed to create shared coverage directory/');

            Expect::that(\getenv(SubprocessCoverage::DIRECTORY_ENV))->toBe($outerDirectory);
            Expect::that(\getenv(SubprocessCoverage::INCLUDE_ENV))->toBe('/outer/src');
            Expect::that($outer->drain()?->toWire())->toBe([
                'files' => ['/outer/src/A.php' => [[1], [2]]],
            ]);
            Expect::that(\getenv(SubprocessCoverage::DIRECTORY_ENV))->toBeFalse();
            Expect::that(\getenv(SubprocessCoverage::INCLUDE_ENV))->toBeFalse();
        } finally {
            $outer->drain();
        }
    }

    private function relayDirectory(): string
    {
        $directory = \getenv(SubprocessCoverage::DIRECTORY_ENV);
        Expect::that($directory)->toBeString();

        return $directory;
    }
}
