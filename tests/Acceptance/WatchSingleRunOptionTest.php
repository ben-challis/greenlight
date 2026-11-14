<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Sandbox\TemporaryDirectory;
use Greenlight\Tests\Support\AcceptanceProject;
use Greenlight\Tests\Support\GreenlightCli;

use function Greenlight\expect;

final readonly class WatchSingleRunOptionTest
{
    public function __construct(private TemporaryDirectory $tempDirectory) {}

    #[Test]
    #[DataSet('singleRunOptions')]
    public function watchRejectsOptionsThatOnlyFitOneRun(string $option, string $message): void
    {
        $project = AcceptanceProject::createWithOnePassingTest($this->tempDirectory, 'watch-single-run-option');

        $result = GreenlightCli::run($project->directory, ['run', '--watch', '--no-ansi', $option]);

        expect($result->exitCode)
            ->because('watch mode MUST reject an option that it cannot apply to each iteration')
            ->toBe(64);
        expect($result->stderr)->toBe('greenlight: ' . $message);
    }

    /** @return iterable<string, array{string, string}> */
    public static function singleRunOptions(): iterable
    {
        yield 'failed tests' => ['--failed', 'Do not use --watch with --failed.'];
        yield 'JUnit file' => ['--reporter=junit=report.xml', 'Do not use --watch with JUnit output.'];
    }
}
