<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Sandbox\TemporaryDirectory;
use Greenlight\Tests\Support\AcceptanceProject;
use Greenlight\Tests\Support\GreenlightCli;
use Greenlight\Tests\Support\ProcessResult;

final readonly class PolicyTest
{
    public function __construct(private TemporaryDirectory $tempDirectory) {}

    #[Test]
    public function diagnosticPoliciesFlipPassedTests(): void
    {
        $project = $this->writeProject();
        // Without flags, all tests pass. Greenlight records deprecations but
        // does not make them fatal.
        $result = $this->run($project, '--filter=DiagnosticProbeTest');
        Expect::that($result->exitCode)->because('diagnostic policies change passed tests to failed')->toBe(0);
        Expect::that($result->output())->toContain('4 tests, 4 passed')
        // Each test uses one matcher. The summary contains those expectations
        // after transfer from the worker.
            ->toContain('4 expectations');
        $result = $this->run($project, '--filter=DiagnosticProbeTest', '--fail-on-deprecation');
        Expect::that($result->exitCode)->because('diagnostic policies change passed tests to failed')->toBe(1);
        Expect::that($result->output())->toContain('4 tests, 3 passed, 1 failed')
            ->toContain('deprecation policy changed this test from passed to failed')
            ->toContain('old api is deprecated')
        // The result change MUST NOT remove verified expectations.
            ->toContain('4 expectations')
        // The deprecation in the allow list does not fail the test.
            ->toContain('PASS PolicyProbe\DiagnosticProbeTest::ignorableDeprecation');
        $result = $this->run($project, '--filter=DiagnosticProbeTest', '--fail-on-notice');
        Expect::that($result->exitCode)->because('diagnostic policies change passed tests to failed')->toBe(1);
        Expect::that($result->output())->toContain('notice policy changed this test from passed to failed')
            ->toContain('a probe notice');
        $result = $this->run($project, '--filter=DiagnosticProbeTest', '--fail-on-warning');
        Expect::that($result->exitCode)->because('diagnostic policies change passed tests to failed')->toBe(1);
        Expect::that($result->output())->toContain('warning policy changed this test from passed to failed')
            ->toContain('a probe warning');
    }

    #[Test]
    public function riskyTestsWarnByDefaultAndFailUnderTheFlag(): void
    {
        $project = $this->writeProject();
        $result = $this->run($project, '--filter=RiskyProbeTest');
        $output = $result->output();
        $riskyBlock = \substr($output, (int) \strpos($output, 'Risky tests:'));
        Expect::that($result->exitCode)->because('risky tests warn by default and fail under the flag')->toBe(0);
        Expect::that($riskyBlock)->toContain('Risky tests: 1');
        Expect::that($riskyBlock)->toContain('These tests passed without a verified expectation.')
            ->toContain('RiskyProbeTest::assertsNothing')
            ->not()->toContain('optedOut')
            ->not()->toContain('mocksOnly');

        // Only the mock verification adds to the count. Tests without an
        // expectation add nothing.
        Expect::that($output)->toContain('1 expectation');
        $result = $this->run($project, '--filter=RiskyProbeTest', '--fail-on-risky');
        Expect::that($result->exitCode)->because('risky tests warn by default and fail under the flag')->toBe(1);
        Expect::that($result->output())->toContain('3 tests, 2 passed, 1 failed')
            ->toContain('fail-on-risky policy changed this test from passed to failed');
    }

    #[Test]
    public function skippedPolicyFailsTheRunAndPreservesReporterOutcomes(): void
    {
        $project = $this->writeProject();
        $result = GreenlightCli::run($project->directory, [
            'run',
            '--workers=1',
            '--filter=SkipProbeTest',
            '--bail=1',
            '--fail-on-skipped',
            '--reporter=plain',
            '--reporter=junit=reports/junit.xml',
            '--reporter=jsonl=reports/events.jsonl',
            '--reporter=teamcity=reports/teamcity.txt',
            '--reporter=github=reports/github.txt',
        ]);

        Expect::that($result->exitCode)
            ->because('the skipped policy MUST fail the run without changing skipped results')
            ->toBe(1);
        Expect::that($result->output())
            ->toContain('SKIP PolicyProbe\SkipProbeTest::skips')
            ->toContain('2 tests, 1 passed, 1 skipped')
            ->toContain('integration service is unavailable')
            ->toContain('fail-on-skipped policy found 1 skipped test');

        $junit = (string) \file_get_contents($project->path('reports/junit.xml'));
        Expect::that($junit)
            ->because('JUnit MUST retain the skipped testcase')
            ->toContain('failures="0"')
            ->toContain('skipped="1"')
            ->toContain('<skipped message="integration service is unavailable"/>');

        $jsonl = (string) \file_get_contents($project->path('reports/events.jsonl'));
        Expect::that($jsonl)
            ->because('JSONL MUST retain skipped result and summary fields')
            ->toContain('"outcome":"skipped"')
            ->toContain('"summary":{"passed":1,"failed":0,"errored":0,"skipped":1}');

        $teamCity = (string) \file_get_contents($project->path('reports/teamcity.txt'));
        Expect::that($teamCity)
            ->because('TeamCity MUST retain its ignored-test message')
            ->toContain("##teamcity[testIgnored name='PolicyProbe\\SkipProbeTest::skips' message='integration service is unavailable'");

        Expect::that((string) \file_get_contents($project->path('reports/github.txt')))
            ->because('GitHub MUST NOT misreport a skipped test as a failed test')
            ->toBe('');
    }

    #[Test]
    public function skippedPolicyWorksAcrossWorkersAndRepeatMode(): void
    {
        $project = $this->writeProject();
        $parallel = $this->run(
            $project,
            '--workers=2',
            '--filter=SkipProbeTest',
            '--fail-on-skipped',
        );

        Expect::that($parallel->exitCode)
            ->because('the run policy MUST use the final process-pool summary')
            ->toBe(1);
        Expect::that($parallel->output())->toContain('2 tests, 1 passed, 1 skipped');

        $repeated = $this->run(
            $project,
            '--filter=SkipProbeTest',
            '--fail-on-skipped',
            '--repeat=2',
        );
        Expect::that($repeated->exitCode)
            ->because('each iteration with a skipped test MUST fail repeat mode')
            ->toBe(1);
        Expect::that($repeated->output())->toContain('Repeat: failed iterations: 1, 2');
    }

    private function run(AcceptanceProject $project, string ...$flags): ProcessResult
    {
        return GreenlightCli::run($project->directory, \array_values(['run', '--reporter=plain', ...$flags]));
    }

    private function writeProject(): AcceptanceProject
    {
        $project = AcceptanceProject::create($this->tempDirectory, 'policy');

        $project->writeFile('tests/DiagnosticProbeTest.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace PolicyProbe;

            use Greenlight\Attribute\Test;
            use Greenlight\Expect\Expect;

            final class DiagnosticProbeTest
            {
                #[Test]
                public function triggersDeprecation(): void
                {
                    \trigger_error('old api is deprecated', \E_USER_DEPRECATED);
                    Expect::that(true)->toBeTrue();
                }

                #[Test]
                public function ignorableDeprecation(): void
                {
                    \trigger_error('vendor noise: legacy shim', \E_USER_DEPRECATED);
                    Expect::that(true)->toBeTrue();
                }

                #[Test]
                public function triggersNotice(): void
                {
                    \trigger_error('a probe notice', \E_USER_NOTICE);
                    Expect::that(true)->toBeTrue();
                }

                #[Test]
                public function triggersWarning(): void
                {
                    \trigger_error('a probe warning', \E_USER_WARNING);
                    Expect::that(true)->toBeTrue();
                }
            }
            PHP);

        $project->writeFile('tests/SkipProbeTest.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace PolicyProbe;

            use Greenlight\Attribute\Test;
            use Greenlight\Expect\Expect;
            use Greenlight\Test\SkipTest;

            final class SkipProbeTest
            {
                #[Test]
                public function skips(): never
                {
                    throw new SkipTest('integration service is unavailable');
                }

                #[Test]
                public function stillRunsAfterTheSkip(): void
                {
                    Expect::that(true)->toBeTrue();
                }
            }
            PHP);

        $project->writeFile('tests/RiskyProbeTest.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace PolicyProbe;

            use Greenlight\Attribute\NoExpectations;
            use Greenlight\Attribute\Test;
            use Greenlight\Doubles\Doubles;

            final class RiskyProbeTest
            {
                public function __construct(private readonly Doubles $doubles) {}

                #[Test]
                public function assertsNothing(): void {}

                #[Test]
                #[NoExpectations]
                public function optedOut(): void {}

                #[Test]
                public function mocksOnly(): void
                {
                    $notifier = $this->doubles->mock(Pingable::class, static function ($plan): void {
                        $plan->expects('ping')->once();
                    });

                    $notifier->ping();
                }
            }

            interface Pingable
            {
                public function ping(): void;
            }
            PHP);

        $project->writeFile('greenlight.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            use Greenlight\Config\GreenlightConfig;

            require_once __DIR__ . '/tests/DiagnosticProbeTest.php';
            require_once __DIR__ . '/tests/RiskyProbeTest.php';
            require_once __DIR__ . '/tests/SkipProbeTest.php';

            return GreenlightConfig::create()
                ->paths([__DIR__ . '/tests'])
                ->ignoreDeprecationsMatching('vendor noise:')
                ->workers(1);
            PHP);

        return $project;
    }
}
