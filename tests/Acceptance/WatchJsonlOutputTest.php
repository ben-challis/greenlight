<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Sandbox\TemporaryDirectory;
use Greenlight\Test\Cleanup;
use Greenlight\Tests\Support\AcceptanceProject;
use Greenlight\Tests\Support\GreenlightCli;

final readonly class WatchJsonlOutputTest
{
    public function __construct(private TemporaryDirectory $directory, private Cleanup $cleanup) {}

    #[Test]
    #[DataSet('outputTargets')]
    public function watchKeepsStatusOutsideTheJsonlEventStream(bool $fileOutput): void
    {
        $project = AcceptanceProject::create($this->directory, 'watch-jsonl');
        $project->writeFile('tests/ProbeTest.php', <<<'PHP'
            <?php

            namespace WatchJsonlProbe;

            use Greenlight\Attribute\Test;

            final class ProbeTest
            {
                #[Test]
                public function passes(): void {}
            }
            PHP);
        $project->configureWithTestFiles(['tests/ProbeTest.php']);
        $reporter = $fileOutput ? '--reporter=jsonl=events.jsonl' : '--reporter=jsonl';
        $ready = $fileOutput ? 'Waiting for changes' : 'run-finished';
        $process = GreenlightCli::start($project->directory, ['run', '--watch', $reporter, '--workers=1']);
        $this->cleanup->defer($process->terminate(...));
        $process->readStdoutUntil($ready, 20.0);
        $process->write("\n");
        $process->readStdoutUntil($ready, 20.0);
        $process->write('q');
        $result = $process->wait(10.0);
        $events = [];
        $lines = $fileOutput
            ? \file($project->path('events.jsonl'), \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES)
            : $result->stdoutLines();

        foreach ($lines === false ? [] : $lines as $line) {
            /** @var array{event: string} $envelope */
            $envelope = \json_decode($line, true, flags: \JSON_THROW_ON_ERROR);
            $events[] = $envelope['event'];
        }

        Expect::that($result->exitCode)->toBe(0);
        Expect::that(\array_count_values($events)['run-started'] ?? 0)->toBe(2);
        Expect::that(\array_count_values($events)['run-finished'] ?? 0)->toBe(2);
        Expect::that($fileOutput ? $result->stdout : $result->stderr)->toContain('Waiting for changes');
    }

    /** @return iterable<string, array{bool}> */
    public static function outputTargets(): iterable
    {
        yield 'standard output' => [false];
        yield 'file' => [true];
    }
}
