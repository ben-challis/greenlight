<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Sandbox\TemporaryDirectory;
use Greenlight\Tests\Support\AcceptanceProject;
use Greenlight\Tests\Support\GreenlightCli;

use function Greenlight\expect;

final readonly class ProfileMachineOutputTest
{
    public function __construct(private TemporaryDirectory $directory) {}

    #[Test]
    #[DataSet('destinations')]
    public function profilingKeepsMachineReportsParseable(string $reporter, bool $fileOutput): void
    {
        $project = AcceptanceProject::createWithOnePassingTest($this->directory, 'profile-machine-output');
        $result = GreenlightCli::run($project->directory, [
            'run',
            '--reporter=' . $reporter . ($fileOutput ? '=report.out' : ''),
            '--profile',
            '--no-ansi',
        ]);

        expect($result->exitCode)->toBe(0);
        $report = $fileOutput ? (string) \file_get_contents($project->path('report.out')) : $result->stdout;

        if ($reporter === 'jsonl') {
            $events = \array_map(
                static fn(string $line): mixed => \json_decode($line, true, flags: \JSON_THROW_ON_ERROR),
                \explode("\n", \trim($report)),
            );
            expect(\array_column($events, 'event'))
                ->toContain('run-started')
                ->toContain('run-finished');
        } else {
            $document = \simplexml_load_string($report);
            expect($document)->toBeInstanceOf(\SimpleXMLElement::class);
            expect((string) $document['tests'])->toBe('1');
        }

        expect($fileOutput ? $result->stdout : $result->stderr)->toContain('Profile:');
        expect($report)->not()->toContain('Profile:');
    }

    /** @return iterable<string, array{string, bool}> */
    public static function destinations(): iterable
    {
        yield 'JSONL stdout' => ['jsonl', false];
        yield 'JSONL file' => ['jsonl', true];
        yield 'JUnit stdout' => ['junit', false];
        yield 'JUnit file' => ['junit', true];
    }
}
