<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Fixture\TempDirectory;
use Greenlight\Tests\Support\AcceptanceProject;
use Greenlight\Tests\Support\GreenlightCli;

final readonly class TemporalExpectationRunTest
{
    public function __construct(private TempDirectory $tempDirectory) {}

    #[Test]
    public function aWorkerPollsRealAsynchronousExternalState(): void
    {
        $project = AcceptanceProject::create($this->tempDirectory, 'temporal-expectation');
        $project->writeFile('tests/AsynchronousAdapterTest.php', <<<'PHP_WRAP'
        <?php

        declare(strict_types=1);

        namespace TemporalProbe;

        use Greenlight\Attribute\Test;
        use Greenlight\Expect\Expect;

        final class AsynchronousAdapterTest
        {
            #[Test]
            public function observesStateWrittenByAnotherProcess(): void
            {
                $marker = \tempnam(\sys_get_temp_dir(), 'greenlight-eventual-');

                if ($marker === false) {
                    throw new \RuntimeException('Could not create the asynchronous marker path.');
                }

                \unlink($marker);
                $process = \proc_open([
                    \PHP_BINARY,
                    '-r',
                    <<<'PHP'
                    fwrite(STDOUT, "ready\n");
                    fflush(STDOUT);

                    if (fgets(STDIN) !== false) {
                        file_put_contents($argv[1], "ready");
                    }
                    PHP,
                    $marker,
                ], [
                    ['pipe', 'r'],
                    ['pipe', 'w'],
                ], $pipes);

                if (!\is_resource($process)) {
                    throw new \RuntimeException('Could not start the asynchronous writer.');
                }

                try {
                    if (
                        !isset($pipes[0], $pipes[1])
                        || !\is_resource($pipes[0])
                        || !\is_resource($pipes[1])
                        || \fgets($pipes[1]) !== "ready\n"
                    ) {
                        throw new \RuntimeException('The asynchronous writer did not become ready.');
                    }

                    \fclose($pipes[1]);
                    $released = false;

                    Expect::eventually(
                        static function () use ($marker, $pipes, &$released): string|false {
                            $state = \is_file($marker) ? \file_get_contents($marker) : false;

                            if (!$released) {
                                \fwrite($pipes[0], "write\n");
                                \fflush($pipes[0]);
                                $released = true;
                            }

                            return $state;
                        },
                    )
                        ->pollEvery(0.010)
                        ->within(1.0)
                        ->toBe('ready');
                } finally {
                    foreach ($pipes as $pipe) {
                        if (\is_resource($pipe)) {
                            \fclose($pipe);
                        }
                    }

                    \proc_close($process);

                    if (\is_file($marker)) {
                        \unlink($marker);
                    }
                }
            }
        }
        PHP_WRAP);
        $project->configureWithTestFiles(['tests/AsynchronousAdapterTest.php']);

        $result = GreenlightCli::run($project->directory, [
            'run',
            '--workers=1',
            '--reporter=plain',
        ]);

        Expect::that($result->exitCode)->because('a worker polls real asynchronous external state')->toBe(0);
        Expect::that($result->output())->toContain('1 test, 1 passed, 1 expectation');
    }
}
