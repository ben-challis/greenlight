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
        $project->write('tests/AsynchronousAdapterTest.php', <<<'PHP_WRAP'
        <?php

        declare(strict_types=1);

        namespace TemporalProbe;

        use Greenlight\Attribute\Test;
        use Greenlight\Attribute\Timeout;
        use Greenlight\Expect\Expect;

        final class AsynchronousAdapterTest
        {
            #[Test]
            #[Timeout(2.0)]
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
                    'usleep(50_000); file_put_contents($argv[1], "ready");',
                    $marker,
                ], [], $pipes);

                if (!\is_resource($process)) {
                    throw new \RuntimeException('Could not start the asynchronous writer.');
                }

                try {
                    Expect::eventually(
                        static fn(): string|false => \file_get_contents($marker),
                    )
                        ->pollEvery(0.010)
                        ->within(1.0)
                        ->toBe('ready');
                } finally {
                    \proc_close($process);

                    if (\is_file($marker)) {
                        \unlink($marker);
                    }
                }
            }
        }
        PHP_WRAP);
        $project->writeConfig(['tests/AsynchronousAdapterTest.php']);

        $result = GreenlightCli::run($project->directory, [
            'run',
            '--workers=1',
            '--reporter=plain',
        ]);

        Expect::that($result->exitCode)->toBe(0)
            ->and($result->output())->toContain('1 test, 1 passed, 1 expectation');
    }
}
