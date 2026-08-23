<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Cli;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Cli\Application;
use Greenlight\Expect\Expect;
use Greenlight\Sandbox\TemporaryDirectory;
use Greenlight\Test\Cleanup;
use Greenlight\Tests\Support\AcceptanceProject;
use Greenlight\Tests\Support\MemoryStream;

final readonly class ApplicationProfileReportTest
{
    public function __construct(
        private TemporaryDirectory $tempDirectory,
        private Cleanup $cleanup,
    ) {}

    #[Test]
    #[DataSet('invalidProfileStreams')]
    public function profileReportRejectsInvalidEventStreams(
        string $projectName,
        string $stream,
        string $diagnostic,
    ): void {
        $project = AcceptanceProject::create($this->tempDirectory, $projectName);
        $project->writeFile('profile.jsonl', $stream);
        $stdout = MemoryStream::open();
        $this->cleanup->defer(static fn() => MemoryStream::close($stdout));
        $stderr = MemoryStream::open();
        $this->cleanup->defer(static fn() => MemoryStream::close($stderr));

        $exit = Application::forStreams($stdout, $stderr)->run([
            'profile:report',
            '--input=profile.jsonl',
            '--no-ansi',
        ], $project->directory);
        \rewind($stdout);
        \rewind($stderr);

        Expect::that($exit)
            ->because('an invalid profile stream MUST fail cleanly')
            ->toBe(1);
        Expect::that(\stream_get_contents($stdout))
            ->because('an invalid profile stream MUST NOT write to standard output')
            ->toBe('');
        Expect::that(\stream_get_contents($stderr))
            ->because('an invalid profile stream MUST write its diagnostic to standard error')
            ->toBe($diagnostic);
    }

    /**
     * @return iterable<string, array{non-empty-string, non-empty-string, non-empty-string}>
     */
    public static function invalidProfileStreams(): iterable
    {
        yield 'invalid JSON' => [
            'profile-invalid-json',
            '{',
            "The input is not a JSONL event stream. A line is not valid JSON.\n",
        ];

        yield 'invalid envelope' => [
            'profile-invalid-envelope',
            '{"event":7,"data":[]}',
            "The input is not a JSONL event stream. A line does not contain an event envelope.\n",
        ];

        yield 'invalid envelope key' => [
            'profile-invalid-envelope-key',
            '{"0":true,"v":2,"event":"future-event","data":{}}',
            "The input is not a JSONL event stream. A line does not contain an event envelope.\n",
        ];

        yield 'unsupported envelope version' => [
            'profile-unsupported-version',
            '{"v":4,"event":"run-started","data":{}}',
            "The input uses unsupported JSONL version 4.\n",
        ];

        yield 'invalid known event payload' => [
            'profile-invalid-payload',
            '{"v":2,"event":"run-started","data":[]}',
            "greenlight: Greenlight could not decode a \"run-started\" event: Wire payload is missing the \"runId\" key.\n",
        ];

        yield 'invalid event data map' => [
            'profile-invalid-data-map',
            '{"v":2,"event":"future-event","data":{"0":true}}',
            "The input is not a JSONL event stream. A line does not contain an event envelope.\n",
        ];

        yield 'no finished run' => [
            'profile-no-finished-run',
            '{"v":2,"event":"future-event","data":[]}',
            "The stream has no finished run to profile.\n",
        ];
    }
}
