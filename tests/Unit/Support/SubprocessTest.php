<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Support;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Expect\Fail;
use Greenlight\Fixture\TempDirectory;
use Greenlight\Tests\Support\ProcessResult;
use Greenlight\Tests\Support\Subprocess;

final readonly class SubprocessTest
{
    public function __construct(private TempDirectory $workspace) {}

    #[Test]
    public function runCapturesTheResultAndHonorsItsExecutionContext(): void
    {
        $result = Subprocess::run(
            $this->workspace->path(),
            [
                \PHP_BINARY,
                '-r',
                <<<'PHP'
                fwrite(STDOUT, getcwd() . "\r\n" . getenv('GREENLIGHT_SUBPROCESS_PROBE') . "\r\n");
                fwrite(STDERR, "warning\r\n");
                exit(7);
                PHP,
            ],
            ['GREENLIGHT_SUBPROCESS_PROBE' => 'environment'],
        );
        $workingDirectory = \realpath($this->workspace->path());

        if ($workingDirectory === false) {
            Fail::because(\sprintf(
                'Expected subprocess working directory at "%s".',
                $this->workspace->path(),
            ));
        }

        Expect::that($result->exitCode)->because('run captures the result and honors its execution context')->toBe(7);
        Expect::that($result->stdout)->toBe($workingDirectory . "\nenvironment");
        Expect::that($result->stderr)->toBe('warning');
    }

    #[Test]
    public function runDrainsLargeOutputsFromBothStreams(): void
    {
        $result = Subprocess::run(
            $this->workspace->path(),
            [
                \PHP_BINARY,
                '-r',
                <<<'PHP'
                fwrite(STDOUT, str_repeat('o', 131072));
                fwrite(STDERR, str_repeat('e', 131072));
                PHP,
            ],
        );

        Expect::that($result->stdout)->because('run drains large outputs from both streams')->toHaveLength(131072);
        Expect::that($result->stderr)->toHaveLength(131072);
    }

    #[Test]
    public function interactiveProcessAcceptsInputAndReturnsItsFinalResult(): void
    {
        $process = Subprocess::start(
            $this->workspace->path(),
            [
                \PHP_BINARY,
                '-r',
                <<<'PHP'
                fwrite(STDOUT, "ready\n");
                fflush(STDOUT);
                $input = fgets(STDIN);
                fwrite(STDOUT, 'received:' . trim($input));
                fwrite(STDERR, "note\n");
                exit(3);
                PHP,
            ],
        );

        try {
            $ready = $process->readStdoutUntil('ready', 2.0);
            $process->write("payload\n");
            $result = $process->wait(2.0);

            Expect::that($ready)->toBe("ready\n");
            Expect::that($result->exitCode)->toBe(3);
            Expect::that($result->stdout)->toBe("ready\nreceived:payload");
            Expect::that($result->stderr)->toBe('note');
        } finally {
            $process->terminate();
        }
    }

    #[Test]
    public function interactiveProcessReceivesTheCompleteInput(): void
    {
        $input = \str_repeat('payload', 131_072);
        $process = Subprocess::start(
            $this->workspace->path(),
            [
                \PHP_BINARY,
                '-r',
                <<<'PHP'
                $input = stream_get_contents(STDIN);
                fwrite(STDOUT, hash('sha256', $input));
                PHP,
            ],
        );

        try {
            $process->write($input);
            $result = $process->complete();

            Expect::that($result->exitCode)
                ->because('a subprocess MUST receive the complete input before stdin closes')
                ->toBe(0);
            Expect::that($result->stdout)
                ->toBe(\hash('sha256', $input));
        } finally {
            $process->terminate();
        }
    }

    #[Test]
    public function readStdoutUntilReportsAProcessThatAlreadyExited(): void
    {
        $process = Subprocess::start(
            $this->workspace->path(),
            [\PHP_BINARY, '-r', 'fwrite(STDERR, "failed\n"); exit(9);'],
        );

        try {
            $result = $process->complete();

            Expect::that($result->exitCode)->toBe(9);
            Expect::that($result->stderr)->toBe('failed');

            Expect::that(static fn(): string => $process->readStdoutUntil('ready', 2.0))
                ->toThrow(\RuntimeException::class, '/Process exited before stdout contained/');
        } finally {
            $process->terminate();
        }
    }

    #[Test]
    public function waitReportsWhenTheProcessMissesItsDeadline(): void
    {
        $process = Subprocess::start(
            $this->workspace->path(),
            [\PHP_BINARY, '-r', 'usleep(2_000_000);'],
        );

        try {
            Expect::that(static fn(): ProcessResult => $process->wait(0.05))
                ->toThrow(\RuntimeException::class, '/Timed out after 0.1s/');
        } finally {
            $process->terminate();
        }
    }
}
