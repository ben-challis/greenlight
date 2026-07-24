<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Support;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Fixture\TempDirectory;
use Greenlight\Tests\Support\ProcessResult;
use Greenlight\Tests\Support\Subprocess;

final readonly class SubprocessTest
{
    public function __construct(private TempDirectory $workspace) {}

    #[Test]
    public function runCapturesTheResultAndHonoursItsExecutionContext(): void
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
            throw new \RuntimeException('Expected subprocess working directory to exist.');
        }

        Expect::that($result->exitCode)->toBe(7)
            ->and($result->stdout)->toBe($workingDirectory . "\nenvironment")
            ->and($result->stderr)->toBe('warning');
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

        Expect::that($result->stdout)->toHaveLength(131072)
            ->and($result->stderr)->toHaveLength(131072);
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
            $process->closeInput();
            $result = $process->wait(2.0);

            Expect::that($ready)->toBe("ready\n")
                ->and($result->exitCode)->toBe(3)
                ->and($result->stdout)->toBe("ready\nreceived:payload")
                ->and($result->stderr)->toBe('note');
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
