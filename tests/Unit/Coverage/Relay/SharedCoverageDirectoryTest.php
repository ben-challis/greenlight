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
use Greenlight\Sandbox\EnvironmentVariables;
use Greenlight\Sandbox\TemporaryDirectory;
use Greenlight\Tests\Support\CoverageJson;

use function Greenlight\expect;

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
            expect($innerDirectory)->not()->toBe($outerDirectory);
            expect(\getenv(SubprocessCoverage::INCLUDE_ENV))->toBe('/inner/src');
            expect($inner->drain()?->toWire())->toBe([
                'files' => ['/inner/src/B.php' => [[3], [4]]],
            ]);
            expect(\getenv(SubprocessCoverage::DIRECTORY_ENV))->toBe($outerDirectory);
            expect(\getenv(SubprocessCoverage::INCLUDE_ENV))->toBe('/outer/src');
            expect(\is_dir($innerDirectory))->toBeFalse();
            expect(\is_file($outerDirectory . '/outer.json'))
                ->because('the inner session must preserve its parent coverage dump')
                ->toBeTrue();

            expect($outer->drain()?->toWire())->toBe([
                'files' => ['/outer/src/A.php' => [[1], [2]]],
            ]);
            expect(\getenv(SubprocessCoverage::DIRECTORY_ENV))->toBe('/original/relay');
            expect(\getenv(SubprocessCoverage::INCLUDE_ENV))->toBe('/original/src');
            expect(\is_dir($outerDirectory))->toBeFalse();
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
            expect()->calling(static fn() => SharedCoverageDirectory::open(
                new CoverageSettings(['/inner/src']),
                $dump,
            ))->toThrow(CoverageError::class, matching: '/Failed to create shared coverage directory/');

            expect(\getenv(SubprocessCoverage::DIRECTORY_ENV))->toBe($outerDirectory);
            expect(\getenv(SubprocessCoverage::INCLUDE_ENV))->toBe('/outer/src');
            expect($outer->drain()?->toWire())->toBe([
                'files' => ['/outer/src/A.php' => [[1], [2]]],
            ]);
            expect(\getenv(SubprocessCoverage::DIRECTORY_ENV))->toBeFalse();
            expect(\getenv(SubprocessCoverage::INCLUDE_ENV))->toBeFalse();
        } finally {
            $outer->drain();
        }
    }

    private function relayDirectory(): string
    {
        $directory = \getenv(SubprocessCoverage::DIRECTORY_ENV);
        expect($directory)->toBeString();

        return $directory;
    }
}
