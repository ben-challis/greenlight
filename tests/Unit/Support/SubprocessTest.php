<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Support;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Core\Test\Cleanup;
use Greenlight\Core\Test\SkipTest;
use Greenlight\Expect\Expect;
use Greenlight\Fixture\TempDirectory;
use Greenlight\Tests\Support\ProcessResult;
use Greenlight\Tests\Support\Subprocess;

final readonly class SubprocessTest
{
    public function __construct(
        private TempDirectory $workspace,
        private Cleanup $cleanup,
    ) {}

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

        Expect::that($workingDirectory)
            ->because(\sprintf(
                'The subprocess working directory at "%s" MUST exist.',
                $this->workspace->path(),
            ))
            ->toBeString();

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
        $this->cleanup->defer($process->terminate(...));

        $ready = $process->readStdoutUntil('ready', 2.0);
        $process->write("payload\n");
        $result = $process->wait(2.0);

        Expect::that($ready)->toBe("ready\n");
        Expect::that($result->exitCode)->toBe(3);
        Expect::that($result->stdout)->toBe("ready\nreceived:payload");
        Expect::that($result->stderr)->toBe('note');
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
        $this->cleanup->defer($process->terminate(...));

        $process->write($input);
        $result = $process->complete();

        Expect::that($result->exitCode)
            ->because('a subprocess MUST receive the complete input before stdin closes')
            ->toBe(0);
        Expect::that($result->stdout)
            ->toBe(\hash('sha256', $input));
    }

    #[Test]
    public function readStdoutUntilReportsAProcessThatAlreadyExited(): void
    {
        $process = Subprocess::start(
            $this->workspace->path(),
            [\PHP_BINARY, '-r', 'fwrite(STDERR, "failed\n"); exit(9);'],
        );
        $this->cleanup->defer($process->terminate(...));

        $result = $process->complete();

        Expect::that($result->exitCode)->toBe(9);
        Expect::that($result->stderr)->toBe('failed');

        Expect::that(static fn(): string => $process->readStdoutUntil('ready', 2.0))
            ->toThrow(\RuntimeException::class, '/Process exited before stdout contained/');
    }

    #[Test]
    public function waitReportsWhenTheProcessMissesItsDeadline(): void
    {
        $process = Subprocess::start(
            $this->workspace->path(),
            [\PHP_BINARY, '-r', 'usleep(2_000_000);'],
        );
        $this->cleanup->defer($process->terminate(...));

        Expect::that(static fn(): ProcessResult => $process->wait(0.05))
            ->toThrow(\RuntimeException::class, '/Timed out after 0.1s/');
    }

    #[Test]
    #[DataSet('nonFiniteTimeouts')]
    public function deadlineOperationsRejectNonFiniteTimeouts(string $operation, float $timeoutSeconds): void
    {
        $process = Subprocess::start(
            $this->workspace->path(),
            [
                \PHP_BINARY,
                '-r',
                $operation === 'wait' ? 'exit(0);' : 'fwrite(STDOUT, "ready");',
            ],
        );
        $this->cleanup->defer($process->terminate(...));

        $call = $operation === 'wait'
            ? static fn(): ProcessResult => $process->wait($timeoutSeconds)
            : static fn(): string => $process->readStdoutUntil('ready', $timeoutSeconds);

        Expect::that($call)
            ->because('a non-finite timeout MUST NOT create an unbounded subprocess wait')
            ->toThrow(
                \InvalidArgumentException::class,
                message: 'Subprocess timeout must be finite.',
            );
    }

    /**
     * @return iterable<string, array{string, float}>
     */
    public static function nonFiniteTimeouts(): iterable
    {
        foreach (['read stdout', 'wait'] as $operation) {
            yield $operation . ', not a number' => [$operation, \NAN];
            yield $operation . ', positive infinity' => [$operation, \INF];
            yield $operation . ', negative infinity' => [$operation, -\INF];
        }
    }

    #[Test]
    public function waitDoesNotDrainAnInheritedPipePastItsDeadline(): void
    {
        if (!\function_exists('pcntl_fork')) {
            throw new SkipTest('The pcntl extension is not available.');
        }

        $process = Subprocess::start(
            $this->workspace->path(),
            [
                \PHP_BINARY,
                '-r',
                <<<'PHP'
                $pid = pcntl_fork();

                if ($pid === -1) {
                    exit(2);
                }

                if ($pid === 0) {
                    usleep(2_000_000);
                    exit(0);
                }

                fwrite(STDOUT, "parent exited\n");
                exit(7);
                PHP,
            ],
        );
        $this->cleanup->defer($process->terminate(...));

        $process->readStdoutUntil('parent exited', 5.0);
        $started = \hrtime(true);
        $result = $process->wait(0.5);
        $elapsedSeconds = (\hrtime(true) - $started) / 1_000_000_000;

        Expect::that($result->exitCode)->toBe(7);
        Expect::that($result->stdout)->toBe('parent exited');
        Expect::that($elapsedSeconds)
            ->because('wait MUST NOT drain a pipe inherited by a descendant past its deadline')
            ->toBeLessThan(1.0);
    }
}
