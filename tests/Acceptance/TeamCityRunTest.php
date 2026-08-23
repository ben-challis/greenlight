<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Sandbox\TemporaryDirectory;
use Greenlight\Tests\Support\AcceptanceProject;
use Greenlight\Tests\Support\GreenlightCli;

final readonly class TeamCityRunTest
{
    public function __construct(private TemporaryDirectory $tempDirectory) {}

    #[Test]
    public function parallelRunEmitsLocationHintsAndFlowIds(): void
    {
        $project = AcceptanceProject::createWithTwoPassingTests($this->tempDirectory, 'teamcity');
        $result = GreenlightCli::run($project->directory, ['run', '--workers=2', '--reporter=teamcity']);
        $output = $result->output();
        $class = $project->testClasses()[0];
        $file = $project->path('tests/FirstPassingTest.php');
        Expect::that($result->exitCode)->because('parallel run emits location hints and flow IDs')->toBe(0);
        Expect::that($output)->toContain(
            "##teamcity[testSuiteStarted name='{$class}' locationHint='php_qn://{$file}::\\{$class}' flowId='{$class}']",
        )
            ->toContain(
                "##teamcity[testStarted name='{$class}::passes' locationHint='php_qn://{$file}::\\{$class}::passes' flowId='{$class}']",
            )
            ->toContain("##teamcity[testSuiteFinished name='{$class}' flowId='{$class}']");
        foreach (\explode("\n", $output) as $line) {
            if (!\str_starts_with($line, '##teamcity[')) {
                continue;
            }

            Expect::that($line)->toContain(" flowId='");
        }
    }
}
