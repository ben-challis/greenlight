<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Fixture\TempDirectory;
use Greenlight\Tests\Fixture\DiscoveryBasic\AlphaTest;
use Greenlight\Tests\Support\AcceptanceProject;
use Greenlight\Tests\Support\GreenlightCli;

/**
 * Drives bin/greenlight with the teamcity reporter against a fixture project
 * and asserts on the service message stream: php_qn:// location hints
 * resolved through the orchestrator's autoloader, a flowId on every message,
 * and suite nesting that survives a multi-worker run.
 */
final readonly class TeamCityRunTest
{
    public function __construct(private TempDirectory $tempDirectory) {}

    #[Test]
    public function parallelRunEmitsLocationHintsAndFlowIds(): void
    {
        // A private copy of ListTestsConfig, so this run cannot race another
        // acceptance test's use of the same working directory. DiscoveryBasic
        // itself stays the single shared copy under tests/Fixture (see
        // AcceptanceProject::copyOfListTestsConfig), so the location hints
        // still resolve to that fixture's real file.
        $project = AcceptanceProject::copyOfListTestsConfig($this->tempDirectory, 'teamcity');
        $result = GreenlightCli::run($project->directory, ['run', '--workers=2', '--reporter=teamcity']);
        $output = $result->output();
        $class = AlphaTest::class;
        $file = (string) \realpath(\dirname(__DIR__) . '/Fixture/DiscoveryBasic/AlphaTest.php');
        Expect::that($result->exitCode)->toBe(0)
            ->and($output)->toContain(
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
