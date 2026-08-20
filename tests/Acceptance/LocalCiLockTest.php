<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Fixture\TempDirectory;
use Greenlight\Tests\Support\Subprocess;

final readonly class LocalCiLockTest
{
    public function __construct(private TempDirectory $tempDirectory) {}

    #[Test]
    public function serializesLocalCommandsAcrossOneSharedSlot(): void
    {
        $lockDirectory = $this->tempDirectory->subdirectory('locks');
        $startedMarker = $this->tempDirectory->path() . '/second-started';
        $environment = [
            'CI' => '0',
            'GREENLIGHT_LOCAL_CI_LOCK_DIRECTORY' => $lockDirectory,
            'GREENLIGHT_LOCAL_CI_MAX_PARALLELISM' => '1',
        ];
        $first = Subprocess::start(
            $this->tempDirectory->path(),
            $this->lockCommand([
                \PHP_BINARY,
                '-r',
                'fwrite(STDOUT, "ready\\n"); fflush(STDOUT); fgets(STDIN);',
            ]),
            $environment,
        );
        $second = null;

        try {
            $first->readStdoutUntil('ready', 2.0);
            $second = Subprocess::start(
                $this->tempDirectory->path(),
                $this->lockCommand([
                    \PHP_BINARY,
                    '-r',
                    'file_put_contents($argv[1], "started");',
                    $startedMarker,
                ]),
                $environment,
            );

            \usleep(250_000);
            Expect::that(\file_exists($startedMarker))
                ->because('the second command MUST wait while the only slot is in use')
                ->toBeFalse();

            $first->write("continue\n");
            Expect::that($first->wait(2.0)->exitCode)->toBe(0);
            Expect::that($second->wait(2.0)->exitCode)->toBe(0);
            Expect::that(\file_get_contents($startedMarker))->toBe('started');
        } finally {
            $first->terminate();
            $second?->terminate();
        }
    }

    #[Test]
    public function hostedCiBypassesTheLocalLock(): void
    {
        $invalidLockDirectory = $this->tempDirectory->path() . '/not-a-directory';
        \file_put_contents($invalidLockDirectory, 'file');
        $result = Subprocess::run(
            $this->tempDirectory->path(),
            $this->lockCommand([\PHP_BINARY, '-r', 'exit(7);']),
            [
                'CI' => 'true',
                'GREENLIGHT_LOCAL_CI_LOCK_DIRECTORY' => $invalidLockDirectory,
            ],
        );

        Expect::that($result->exitCode)->because('hosted CI bypasses the local lock')->toBe(7);
    }

    #[Test]
    public function permitsCommandsUpToTheConfiguredParallelism(): void
    {
        $environment = [
            'CI' => '0',
            'GREENLIGHT_LOCAL_CI_LOCK_DIRECTORY' => $this->tempDirectory->subdirectory('two-locks'),
            'GREENLIGHT_LOCAL_CI_MAX_PARALLELISM' => '2',
        ];
        $first = Subprocess::start(
            $this->tempDirectory->path(),
            $this->lockCommand([
                \PHP_BINARY,
                '-r',
                'fwrite(STDOUT, "ready\\n"); fflush(STDOUT); fgets(STDIN);',
            ]),
            $environment,
        );

        try {
            $first->readStdoutUntil('ready', 2.0);
            $second = Subprocess::run(
                $this->tempDirectory->path(),
                $this->lockCommand([\PHP_BINARY, '-r', 'exit(9);']),
                $environment,
            );

            Expect::that($second->exitCode)
                ->because('the second command can use the configured second slot')
                ->toBe(9);
            $first->write("continue\n");
            Expect::that($first->wait(2.0)->exitCode)->toBe(0);
        } finally {
            $first->terminate();
        }
    }

    /**
     * @param non-empty-list<string> $command
     *
     * @return non-empty-list<string>
     */
    private function lockCommand(array $command): array
    {
        return [
            \PHP_BINARY,
            \dirname(__DIR__, 2) . '/tools/local-ci-lock.php',
            '--',
            ...$command,
        ];
    }
}
