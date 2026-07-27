<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Fixture\TempDirectory;
use Greenlight\Tests\Support\AcceptanceProject;
use Greenlight\Tests\Support\GreenlightCli;

final readonly class ProfileReportInputTest
{
    public function __construct(private TempDirectory $tempDirectory) {}

    #[Test]
    #[DataSet('invalidStreams')]
    public function invalidEventStreamsFailWithExactGuidance(
        string $case,
        string $stream,
        string $message,
    ): void {
        $project = AcceptanceProject::create($this->tempDirectory, 'profile-report-' . $case);
        $project->writeFile('events.jsonl', $stream . "\n");

        $result = GreenlightCli::run(
            $project->directory,
            ['profile:report', '--no-ansi', '--input=events.jsonl'],
        );

        Expect::that($result->exitCode)
            ->because('invalid event streams fail with exact guidance')
            ->toBe(1)
            ->and($result->stdout)
            ->toBe('')
            ->and($result->stderr)
            ->toBe($message);
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function invalidStreams(): iterable
    {
        yield 'invalid JSON' => [
            'invalid-json',
            '{',
            'The input is not a JSONL event stream. A line is not valid JSON.',
        ];

        yield 'invalid envelope' => [
            'invalid-envelope',
            '{"event":"run-started","data":"invalid"}',
            'The input is not a JSONL event stream. A line does not contain an event envelope.',
        ];

        yield 'invalid known event' => [
            'invalid-event',
            '{"event":"run-started","data":{}}',
            'greenlight: Greenlight could not decode a "run-started" event: Wire payload is missing the "runId" key.',
        ];

        yield 'unknown event only' => [
            'unknown-event',
            '{"event":"future-event","data":{}}',
            'The stream has no finished run to profile.',
        ];
    }
}
