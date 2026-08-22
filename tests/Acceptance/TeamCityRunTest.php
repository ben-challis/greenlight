<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Sandbox\TemporaryDirectory;
use Greenlight\Tests\Fixture\DiscoveryBasic\AlphaTest;
use Greenlight\Tests\Support\AcceptanceProject;
use Greenlight\Tests\Support\ClassFile;
use Greenlight\Tests\Support\GreenlightCli;

final readonly class TeamCityRunTest
{
    public function __construct(private TemporaryDirectory $tempDirectory) {}

    #[Test]
    public function parallelRunEmitsLocationHintsAndFlowIds(): void
    {
        // An isolated project prevents a conflict with another acceptance
        // test in the same directory. DiscoveryBasic remains the single shared
        // copy in tests/Fixture. Thus, location hints identify its actual file.
        $project = AcceptanceProject::createWithDiscoveryBasicTests($this->tempDirectory, 'teamcity');
        $result = GreenlightCli::run($project->directory, ['run', '--workers=2', '--reporter=teamcity']);
        $output = $result->output();
        $class = AlphaTest::class;
        $file = ClassFile::of($class);
        Expect::that($result->exitCode)->because('parallel run emits location hints and flow IDs')->toBe(0);
        Expect::that($output)->toContain(
            "##teamcity[testSuiteStarted name='{$class}' locationHint='php_qn://{$file}::\\{$class}' flowId='{$class}']",
        )
            ->toContain(
                "##teamcity[testStarted name='{$class}::one' locationHint='php_qn://{$file}::\\{$class}::one' flowId='{$class}']",
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
