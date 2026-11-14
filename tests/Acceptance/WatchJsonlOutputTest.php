<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Sandbox\TemporaryDirectory;
use Greenlight\Test\Cleanup;
use Greenlight\Tests\Support\AcceptanceProject;
use Greenlight\Tests\Support\GreenlightCli;

use function Greenlight\expect;

final readonly class WatchJsonlOutputTest
{
    public function __construct(private TemporaryDirectory $directory, private Cleanup $cleanup) {}

    #[Test]
    #[DataSet('outputTargets')]
    public function watchKeepsStatusOutsideTheJsonlEventStream(bool $fileOutput): void
    {
        $project = AcceptanceProject::createWithOnePassingTest($this->directory, 'watch-jsonl');
        $reporter = $fileOutput ? '--reporter=jsonl=events.jsonl' : '--reporter=jsonl';
        $process = GreenlightCli::start($project->directory, ['run', '--watch', $reporter, '--workers=1']);
        $this->cleanup->defer($process->terminate(...));
        // Watch mode discards keys that arrive during a run. Thus, each key
        // waits for the ready line, which is on standard error with jsonl.
        $readReady = $fileOutput ? $process->readStdoutUntil(...) : $process->readStderrUntil(...);
        $readReady('Waiting for changes', 20.0);
        $process->write("\n");
        $readReady('Waiting for changes', 20.0);
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

        expect($result->exitCode)->toBe(0);
        expect(\array_count_values($events)['run-started'] ?? 0)->toBe(2);
        expect(\array_count_values($events)['run-finished'] ?? 0)->toBe(2);
        expect($fileOutput ? $result->stdout : $result->stderr)->toContain('Waiting for changes');
    }

    /** @return iterable<string, array{bool}> */
    public static function outputTargets(): iterable
    {
        yield 'standard output' => [false];
        yield 'file' => [true];
    }
}
