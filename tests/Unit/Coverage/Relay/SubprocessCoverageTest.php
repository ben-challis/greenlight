<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Coverage\Relay;

use Greenlight\Attribute\Test;
use Greenlight\Coverage\Collection\CoverageSettings;
use Greenlight\Coverage\Collection\Driver\DriverSelector;
use Greenlight\Coverage\CoverageMap;
use Greenlight\Coverage\FileCoverage;
use Greenlight\Coverage\Relay\SharedCoverageDirectory;
use Greenlight\Coverage\Relay\SubprocessCoverage;
use Greenlight\Expect\Fail;
use Greenlight\Sandbox\EnvironmentVariables;
use Greenlight\Sandbox\TemporaryDirectory;
use Greenlight\Tests\Fixture\Coverage\AvailableFakeDriver;
use Greenlight\Tests\Fixture\Coverage\RecordingFakeDriver;
use Greenlight\Tests\Fixture\Coverage\UnavailableFakeDriver;
use Greenlight\Tests\Support\CoverageJson;
use Greenlight\Tests\Support\PhpSubprocess;
use Greenlight\Tests\Support\SourceOnlyPhp;

use function Greenlight\expect;

final readonly class SubprocessCoverageTest
{
    public function __construct(
        private TemporaryDirectory $tempDirectory,
        private EnvironmentVariables $environment,
    ) {}

    #[Test]
    public function openExportsTheRelayVariablesAndDrainRestoresThem(): void
    {
        $this->environment->set(SubprocessCoverage::DIRECTORY_ENV, '/outer/dir');
        $this->environment->unset(SubprocessCoverage::INCLUDE_ENV);
        $shared = SharedCoverageDirectory::open(new CoverageSettings(['/project/src', '/project/lib']));

        $directory = \getenv(SubprocessCoverage::DIRECTORY_ENV);

        expect($directory)->toBeString();
        expect($directory !== '/outer/dir')->toBeTrue();
        expect(\is_dir($directory))->toBeTrue();
        expect(\getenv(SubprocessCoverage::INCLUDE_ENV))->toBe('/project/src' . \PATH_SEPARATOR . '/project/lib');
        expect(SubprocessCoverage::requested())->toBeTrue();

        expect($shared->drain())->toBeNull();

        expect(\getenv(SubprocessCoverage::DIRECTORY_ENV))->toBe('/outer/dir');
        expect(\getenv(SubprocessCoverage::INCLUDE_ENV))->toBeFalse();
        expect(\is_dir($directory))->toBeFalse();
    }

    #[Test]
    public function openRejectsABlockedSystemTemporaryDirectory(): void
    {
        $blocker = $this->tempDirectory->path() . '/not-a-directory';

        expect(\file_put_contents($blocker, 'blocked'))
            ->because('the test setup MUST create the blocking file')
            ->toBe(7);

        $root = \dirname(__DIR__, 4);
        $result = PhpSubprocess::run($root, SourceOnlyPhp::command(
            $root . '/src',
            <<<'PHP'
            use function Greenlight\expect;

            require_once 'src/Expect/functions.php';

            $blocker = $argv[1];
            $message = '/^Failed to create shared coverage directory "'
                . preg_quote($blocker, '/')
                . '\/greenlight-coverage-[0-9a-f]{12}": mkdir\(\): Not a directory\.$/D';

            expect()->calling(
                static fn(): \Greenlight\Coverage\Relay\SharedCoverageDirectory =>
                    \Greenlight\Coverage\Relay\SharedCoverageDirectory::open(new \Greenlight\Coverage\Collection\CoverageSettings([])),
            )
                ->because('coverage setup MUST reject a blocked system temp directory')
                ->toThrow(\Greenlight\Coverage\CoverageError::class, matching: $message);

            fwrite(STDOUT, 'matched');
            PHP,
            phpOptions: ['-d', 'sys_temp_dir=' . $blocker],
            arguments: [$blocker],
        ));

        expect($result->exitCode)
            ->because('coverage setup MUST fail before it exports a nonexistent relay directory')
            ->toBe(0);
        expect($result->stdout)
            ->because('the child MUST emit success only after the exact coverage error matches')
            ->toBe('matched');
        expect($result->stderr)
            ->toBe('');
    }

    #[Test]
    public function drainMergesEveryDumpAndSkipsUnparseableOnes(): void
    {
        $this->environment->unset(SubprocessCoverage::DIRECTORY_ENV);
        $this->environment->unset(SubprocessCoverage::INCLUDE_ENV);
        $shared = SharedCoverageDirectory::open(new CoverageSettings([]));
        $directory = \getenv(SubprocessCoverage::DIRECTORY_ENV);

        expect($directory)
            ->because(\sprintf('%s MUST contain a relay directory.', SubprocessCoverage::DIRECTORY_ENV))
            ->toBeString();

        $this->dump($directory, 'a.json', new CoverageMap([new FileCoverage('/app/a.php', [1, 2], [3])]));
        $this->dump($directory, 'b.json', new CoverageMap([new FileCoverage('/app/a.php', [3], []), new FileCoverage('/app/b.php', [7], [])]));
        \file_put_contents($directory . '/truncated.json', '{"v":1,"files":{');

        $merged = $shared->drain();

        expect($merged)
            ->because('SharedCoverageDirectory::drain() MUST return CoverageMap.')
            ->toBeInstanceOf(CoverageMap::class);

        $files = $merged->files();

        expect(\array_keys($files))->toBe(['/app/a.php', '/app/b.php']);
        expect($files['/app/a.php']->coveredLines)->toBe([1, 2, 3]);
        expect($files['/app/a.php']->uncoveredLines)->toBe([]);
        expect($files['/app/b.php']->coveredLines)->toBe([7]);
        expect(\is_dir($directory))->toBeFalse();
    }

    #[Test]
    public function drainSkipsUnreadableDumpsAndCleansTheRelayDirectory(): void
    {
        $this->environment->set(SubprocessCoverage::DIRECTORY_ENV, '/outer/dir');
        $this->environment->unset(SubprocessCoverage::INCLUDE_ENV);
        $shared = SharedCoverageDirectory::open(new CoverageSettings([]));
        $directory = \getenv(SubprocessCoverage::DIRECTORY_ENV);

        expect($directory)
            ->because('The coverage relay directory environment variable MUST contain a path.')
            ->toBeString();

        $unreadable = $directory . '/unreadable.json';

        if (!\symlink($directory . '/missing.json', $unreadable)) {
            Fail::because('Expected to create an unreadable coverage dump symlink.');
        }

        expect($shared->drain())
            ->because('drain skips unreadable coverage dumps')
            ->toBeNull();
        expect(\is_link($unreadable))
            ->because('drain removes unreadable coverage dumps')
            ->toBeFalse();
        expect(\is_dir($directory))
            ->because('drain removes the empty relay directory')
            ->toBeFalse();
        expect(\getenv(SubprocessCoverage::DIRECTORY_ENV))
            ->because('drain restores the previous relay directory')
            ->toBe('/outer/dir');
        expect(\getenv(SubprocessCoverage::INCLUDE_ENV))
            ->because('drain restores an unset include-path variable')
            ->toBeFalse();
    }

    #[Test]
    public function beginDoesNothingWithoutTheRelayVariables(): void
    {
        $this->environment->unset(SubprocessCoverage::DIRECTORY_ENV);

        expect(SubprocessCoverage::requested())->toBeFalse();
        expect(SubprocessCoverage::begin())->toBeNull();
    }

    #[Test]
    public function zeroDirectoryStillStartsSubprocessCoverage(): void
    {
        $this->environment->set(SubprocessCoverage::DIRECTORY_ENV, '0');
        $relay = SubprocessCoverage::begin(new DriverSelector([RecordingFakeDriver::class]));

        expect($relay)
            ->because('The non-empty falsey directory MUST start subprocess coverage.')
            ->toBeInstanceOf(SubprocessCoverage::class);

        expect(SubprocessCoverage::requested())
            ->because('a non-empty falsey directory MUST request subprocess coverage')
            ->toBeTrue();
        expect(RecordingFakeDriver::started())
            ->because('a non-empty falsey directory MUST start the selected coverage driver')
            ->toBeTrue();

        $relay->write();
    }

    #[Test]
    public function beginWritesFilteredCoverageToTheRelayDirectory(): void
    {
        $directory = $this->tempDirectory->subdirectory('filtered-relay');
        $this->environment->set(SubprocessCoverage::DIRECTORY_ENV, $directory);
        $this->environment->set(
            SubprocessCoverage::INCLUDE_ENV,
            \PATH_SEPARATOR . '/project/src' . \PATH_SEPARATOR,
        );

        $relay = SubprocessCoverage::begin(new DriverSelector([RecordingFakeDriver::class]));

        expect($relay)
            ->because('The available fake driver MUST start subprocess coverage.')
            ->toBeInstanceOf(SubprocessCoverage::class);

        expect(RecordingFakeDriver::started())
            ->because('subprocess coverage starts the selected driver')
            ->toBeTrue();

        $relay->write();

        expect(RecordingFakeDriver::started())
            ->because('writing subprocess coverage stops the selected driver')
            ->toBeFalse();

        $dumps = \glob($directory . '/*.json');

        expect($dumps)
            ->because('Subprocess coverage MUST write a list of JSON dumps.')
            ->toBeArray();
        expect($dumps)
            ->because('Subprocess coverage MUST write exactly one JSON dump.')
            ->toHaveCount(1);

        $files = CoverageJson::read($dumps[0])->files();

        expect(\array_keys($files))
            ->because('empty include segments are ignored and the configured path filters the dump')
            ->toBe(['/project/src/Included.php']);
        expect($files['/project/src/Included.php']->coveredLines)
            ->toBe([10]);
        expect($files['/project/src/Included.php']->uncoveredLines)
            ->toBe([11]);
    }

    #[Test]
    public function anUnavailableRelayDirectoryDoesNotBreakTheSubprocess(): void
    {
        $directory = $this->tempDirectory->path() . '/missing/relay';
        $this->environment->set(SubprocessCoverage::DIRECTORY_ENV, $directory);
        $this->environment->unset(SubprocessCoverage::INCLUDE_ENV);
        $relay = SubprocessCoverage::begin(new DriverSelector([RecordingFakeDriver::class]));

        expect($relay)
            ->because('The available fake driver MUST start subprocess coverage.')
            ->toBeInstanceOf(SubprocessCoverage::class);

        $relay->write();

        expect(RecordingFakeDriver::started())
            ->because('a failed relay write MUST still stop the coverage driver')
            ->toBeFalse();
        expect(\file_exists($directory))
            ->because('a failed relay write MUST NOT create an incomplete relay directory')
            ->toBeFalse();
    }

    #[Test]
    public function emptyCoverageDoesNotWriteARelayFile(): void
    {
        $directory = $this->tempDirectory->subdirectory('empty-relay');
        $this->environment->set(SubprocessCoverage::DIRECTORY_ENV, $directory);
        $this->environment->unset(SubprocessCoverage::INCLUDE_ENV);
        $relay = SubprocessCoverage::begin(new DriverSelector([AvailableFakeDriver::class]));

        expect($relay)
            ->because('The available fake driver MUST start subprocess coverage.')
            ->toBeInstanceOf(SubprocessCoverage::class);

        $relay->write();

        expect(\glob($directory . '/*.json'))
            ->because('subprocess coverage does not write an empty map')
            ->toBe([]);
    }

    #[Test]
    public function unavailableCoverageDriverDoesNotStartARelay(): void
    {
        $directory = $this->tempDirectory->subdirectory('unavailable-relay');
        $this->environment->set(SubprocessCoverage::DIRECTORY_ENV, $directory);
        $this->environment->unset(SubprocessCoverage::INCLUDE_ENV);

        expect(SubprocessCoverage::begin(new DriverSelector([UnavailableFakeDriver::class])))
            ->because('a missing coverage driver does not fail a subprocess run')
            ->toBeNull();
        expect(\glob($directory . '/*.json'))
            ->toBe([]);
    }

    #[Test]
    public function drainRestoresZeroRelayValuesExactly(): void
    {
        $this->environment->set(SubprocessCoverage::DIRECTORY_ENV, '0');
        $this->environment->set(SubprocessCoverage::INCLUDE_ENV, '0');
        $shared = SharedCoverageDirectory::open(new CoverageSettings([]));
        $shared->drain();

        expect(\getenv(SubprocessCoverage::DIRECTORY_ENV))
            ->because('drain MUST restore falsey relay values exactly')
            ->toBe('0');
        expect(\getenv(SubprocessCoverage::INCLUDE_ENV))
            ->toBe('0');
    }

    private function dump(string $directory, string $name, CoverageMap $map): void
    {
        CoverageJson::write($directory . '/' . $name, $map);
    }
}
