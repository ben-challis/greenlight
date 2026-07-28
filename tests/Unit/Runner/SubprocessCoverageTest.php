<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Runner;

use Greenlight\Attribute\Test;
use Greenlight\Coverage\CoverageMap;
use Greenlight\Coverage\Driver\DriverSelector;
use Greenlight\Coverage\Export\JsonExporter;
use Greenlight\Coverage\FileCoverage;
use Greenlight\Expect\Expect;
use Greenlight\Expect\Fail;
use Greenlight\Fixture\EnvironmentSandbox;
use Greenlight\Fixture\TempDirectory;
use Greenlight\Runner\CoverageSettings;
use Greenlight\Runner\SharedCoverageDirectory;
use Greenlight\Runner\SubprocessCoverage;
use Greenlight\Tests\Fixture\Coverage\AvailableFakeDriver;
use Greenlight\Tests\Fixture\Coverage\RecordingFakeDriver;
use Greenlight\Tests\Fixture\Coverage\UnavailableFakeDriver;

final readonly class SubprocessCoverageTest
{
    public function __construct(private TempDirectory $tempDirectory) {}

    #[Test]
    public function openExportsTheRelayVariablesAndDrainRestoresThem(): void
    {
        $sandbox = new EnvironmentSandbox();
        $sandbox->set(SubprocessCoverage::DIRECTORY_ENV, '/outer/dir');
        $sandbox->unset(SubprocessCoverage::INCLUDE_ENV);

        try {
            $shared = SharedCoverageDirectory::open(new CoverageSettings(['/project/src', '/project/lib']));

            $directory = \getenv(SubprocessCoverage::DIRECTORY_ENV);

            Expect::that(\is_string($directory) && $directory !== '/outer/dir')->toBeTrue()
                ->and(\is_string($directory) && \is_dir($directory))->toBeTrue()
                ->and(\getenv(SubprocessCoverage::INCLUDE_ENV))->toBe('/project/src' . \PATH_SEPARATOR . '/project/lib')
                ->and(SubprocessCoverage::requested())->toBeTrue();

            Expect::that($shared->drain())->toBeNull();

            Expect::that(\getenv(SubprocessCoverage::DIRECTORY_ENV))->toBe('/outer/dir')
                ->and(\getenv(SubprocessCoverage::INCLUDE_ENV))->toBeFalse()
                ->and(\is_string($directory) && \is_dir($directory))->toBeFalse();
        } finally {
            $sandbox->dispose();
        }
    }

    #[Test]
    public function drainMergesEveryDumpAndSkipsUnparseableOnes(): void
    {
        $sandbox = new EnvironmentSandbox();
        $sandbox->unset(SubprocessCoverage::DIRECTORY_ENV);
        $sandbox->unset(SubprocessCoverage::INCLUDE_ENV);

        try {
            $shared = SharedCoverageDirectory::open(new CoverageSettings([]));
            $directory = \getenv(SubprocessCoverage::DIRECTORY_ENV);

            if (!\is_string($directory)) {
                Fail::because(\sprintf(
                    'Expected %s to contain a relay directory, got %s.',
                    SubprocessCoverage::DIRECTORY_ENV,
                    \get_debug_type($directory),
                ));
            }

            $this->dump($directory, 'a.json', new CoverageMap([new FileCoverage('/app/a.php', [1, 2], [3])]));
            $this->dump($directory, 'b.json', new CoverageMap([new FileCoverage('/app/a.php', [3], []), new FileCoverage('/app/b.php', [7], [])]));
            \file_put_contents($directory . '/truncated.json', '{"v":1,"files":{');

            $merged = $shared->drain();

            if (!$merged instanceof CoverageMap) {
                Fail::because(\sprintf(
                    'Expected SharedCoverageDirectory::drain() to return CoverageMap, got %s.',
                    \get_debug_type($merged),
                ));
            }

            $files = $merged->files();

            Expect::that(\array_keys($files))->toBe(['/app/a.php', '/app/b.php'])
                ->and($files['/app/a.php']->coveredLines)->toBe([1, 2, 3])
                ->and($files['/app/a.php']->uncoveredLines)->toBe([])
                ->and($files['/app/b.php']->coveredLines)->toBe([7])
                ->and(\is_dir($directory))->toBeFalse();
        } finally {
            $sandbox->dispose();
        }
    }

    #[Test]
    public function drainSkipsUnreadableDumpsAndCleansTheRelayDirectory(): void
    {
        $sandbox = new EnvironmentSandbox();
        $sandbox->set(SubprocessCoverage::DIRECTORY_ENV, '/outer/dir');
        $sandbox->unset(SubprocessCoverage::INCLUDE_ENV);

        try {
            $shared = SharedCoverageDirectory::open(new CoverageSettings([]));
            $directory = \getenv(SubprocessCoverage::DIRECTORY_ENV);

            if (!\is_string($directory)) {
                Fail::because('Expected the coverage relay directory environment variable to contain a path.');
            }

            $unreadable = $directory . '/unreadable.json';

            if (!\symlink($directory . '/missing.json', $unreadable)) {
                Fail::because('Expected to create an unreadable coverage dump symlink.');
            }

            Expect::that($shared->drain())
                ->because('drain skips unreadable coverage dumps')
                ->toBeNull()
                ->and(\is_link($unreadable))
                ->because('drain removes unreadable coverage dumps')
                ->toBeFalse()
                ->and(\is_dir($directory))
                ->because('drain removes the empty relay directory')
                ->toBeFalse()
                ->and(\getenv(SubprocessCoverage::DIRECTORY_ENV))
                ->because('drain restores the previous relay directory')
                ->toBe('/outer/dir')
                ->and(\getenv(SubprocessCoverage::INCLUDE_ENV))
                ->because('drain restores an unset include-path variable')
                ->toBeFalse();
        } finally {
            $sandbox->dispose();
        }
    }

    #[Test]
    public function beginDoesNothingWithoutTheRelayVariables(): void
    {
        $sandbox = new EnvironmentSandbox();
        $sandbox->unset(SubprocessCoverage::DIRECTORY_ENV);

        try {
            Expect::that(SubprocessCoverage::requested())->toBeFalse()
                ->and(SubprocessCoverage::begin())->toBeNull();
        } finally {
            $sandbox->dispose();
        }
    }

    #[Test]
    public function beginWritesFilteredCoverageToTheRelayDirectory(): void
    {
        $sandbox = new EnvironmentSandbox();
        $directory = $this->tempDirectory->subdirectory('filtered-relay');
        $sandbox->set(SubprocessCoverage::DIRECTORY_ENV, $directory);
        $sandbox->set(
            SubprocessCoverage::INCLUDE_ENV,
            \PATH_SEPARATOR . '/project/src' . \PATH_SEPARATOR,
        );

        try {
            $relay = SubprocessCoverage::begin(new DriverSelector([RecordingFakeDriver::class]));

            if (!$relay instanceof SubprocessCoverage) {
                Fail::because('Expected the available fake driver to start subprocess coverage.');
            }

            Expect::that(RecordingFakeDriver::started())
                ->because('subprocess coverage starts the selected driver')
                ->toBeTrue();

            $relay->write();

            Expect::that(RecordingFakeDriver::started())
                ->because('writing subprocess coverage stops the selected driver')
                ->toBeFalse();

            $dumps = \glob($directory . '/*.json');

            if (!\is_array($dumps) || \count($dumps) !== 1) {
                Fail::because('Expected subprocess coverage to write exactly one JSON dump.');
            }

            $json = \file_get_contents($dumps[0]);

            if (!\is_string($json)) {
                Fail::because('Expected to read the subprocess coverage JSON dump.');
            }

            $files = JsonExporter::import($json)->files();

            Expect::that(\array_keys($files))
                ->because('empty include segments are ignored and the configured path filters the dump')
                ->toBe(['/project/src/Included.php'])
                ->and($files['/project/src/Included.php']->coveredLines)
                ->toBe([10])
                ->and($files['/project/src/Included.php']->uncoveredLines)
                ->toBe([11]);
        } finally {
            $sandbox->dispose();
        }
    }

    #[Test]
    public function anUnavailableRelayDirectoryDoesNotBreakTheSubprocess(): void
    {
        $sandbox = new EnvironmentSandbox();
        $directory = $this->tempDirectory->path() . '/missing/relay';
        $sandbox->set(SubprocessCoverage::DIRECTORY_ENV, $directory);
        $sandbox->unset(SubprocessCoverage::INCLUDE_ENV);

        try {
            $relay = SubprocessCoverage::begin(new DriverSelector([RecordingFakeDriver::class]));

            if (!$relay instanceof SubprocessCoverage) {
                Fail::because('Expected the available fake driver to start subprocess coverage.');
            }

            $relay->write();

            Expect::that(RecordingFakeDriver::started())
                ->because('a failed relay write MUST still stop the coverage driver')
                ->toBeFalse()
                ->and(\file_exists($directory))
                ->because('a failed relay write MUST NOT create an incomplete relay directory')
                ->toBeFalse();
        } finally {
            $sandbox->dispose();
        }
    }

    #[Test]
    public function emptyCoverageDoesNotWriteARelayFile(): void
    {
        $sandbox = new EnvironmentSandbox();
        $directory = $this->tempDirectory->subdirectory('empty-relay');
        $sandbox->set(SubprocessCoverage::DIRECTORY_ENV, $directory);
        $sandbox->unset(SubprocessCoverage::INCLUDE_ENV);

        try {
            $relay = SubprocessCoverage::begin(new DriverSelector([AvailableFakeDriver::class]));

            if (!$relay instanceof SubprocessCoverage) {
                Fail::because('Expected the available fake driver to start subprocess coverage.');
            }

            $relay->write();

            Expect::that(\glob($directory . '/*.json'))
                ->because('subprocess coverage does not write an empty map')
                ->toBe([]);
        } finally {
            $sandbox->dispose();
        }
    }

    #[Test]
    public function unavailableCoverageDriverDoesNotStartARelay(): void
    {
        $sandbox = new EnvironmentSandbox();
        $directory = $this->tempDirectory->subdirectory('unavailable-relay');
        $sandbox->set(SubprocessCoverage::DIRECTORY_ENV, $directory);
        $sandbox->unset(SubprocessCoverage::INCLUDE_ENV);

        try {
            Expect::that(SubprocessCoverage::begin(new DriverSelector([UnavailableFakeDriver::class])))
                ->because('a missing coverage driver does not fail a subprocess run')
                ->toBeNull()
                ->and(\glob($directory . '/*.json'))
                ->toBe([]);
        } finally {
            $sandbox->dispose();
        }
    }

    private function dump(string $directory, string $name, CoverageMap $map): void
    {
        $export = new JsonExporter()->export($map);
        \file_put_contents($directory . '/' . $name, \reset($export));
    }
}
