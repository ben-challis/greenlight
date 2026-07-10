<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Tests\Fixture\DiscoveryBasic\AlphaTest;
use Greenlight\Tests\Support\AcceptanceProject;

/**
 * Drives bin/greenlight with the teamcity reporter against a fixture project
 * and asserts on the service message stream: php_qn:// location hints
 * resolved through the orchestrator's autoloader, a flowId on every message,
 * and suite nesting that survives a multi-worker run.
 */
final class TeamCityRunTest
{
    #[Test]
    public function parallelRunEmitsLocationHintsAndFlowIds(): void
    {
        [$exit, $output] = $this->runIn('ListTestsConfig', ['run', '--workers=2', '--reporter=teamcity']);

        $class = AlphaTest::class;
        $file = (string) \realpath(\dirname(__DIR__) . '/Fixture/DiscoveryBasic/AlphaTest.php');

        Expect::that($exit)->toBe(0)
            ->and($output)->toContain(
                "##teamcity[testSuiteStarted name='{$class}' locationHint='php_qn://{$file}::\\{$class}' flowId='{$class}']",
            )
            ->and($output)->toContain(
                "##teamcity[testStarted name='{$class}::one' locationHint='php_qn://{$file}::\\{$class}::one' flowId='{$class}']",
            )
            ->and($output)->toContain("##teamcity[testSuiteFinished name='{$class}' flowId='{$class}']");

        foreach (\explode("\n", $output) as $line) {
            if (!\str_starts_with($line, '##teamcity[')) {
                continue;
            }

            Expect::that($line)->toContain(" flowId='");
        }
    }

    /**
     * @param list<string> $arguments
     *
     * @return array{int, string}
     */
    private function runIn(string $fixtureConfigDir, array $arguments): array
    {
        return AcceptanceProject::runIn(\dirname(__DIR__) . '/Fixture/' . $fixtureConfigDir, $arguments);
    }
}
