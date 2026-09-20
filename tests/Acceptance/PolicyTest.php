<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\Test;
use Greenlight\Sandbox\TemporaryDirectory;
use Greenlight\Tests\Support\AcceptanceProject;
use Greenlight\Tests\Support\GreenlightCli;
use Greenlight\Tests\Support\ProcessResult;

use function Greenlight\expect;

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
        expect($result->exitCode)->because('diagnostic policies change passed tests to failed')->toBe(0);
        expect($result->output())->toContain('4 tests, 4 passed')
        // Each test uses one matcher. The summary contains those expectations
        // after transfer from the worker.
            ->toContain('4 expectations');
        $result = $this->run($project, '--filter=DiagnosticProbeTest', '--fail-on-deprecation');
        expect($result->exitCode)->because('diagnostic policies change passed tests to failed')->toBe(1);
        expect($result->output())->toContain('4 tests, 3 passed, 1 failed')
            ->toContain('deprecation policy changed this test from passed to failed')
            ->toContain('old api is deprecated')
        // The result change MUST NOT remove verified expectations.
            ->toContain('4 expectations')
        // The deprecation in the allow list does not fail the test.
            ->toContain('PASS PolicyProbe\DiagnosticProbeTest::ignorableDeprecation');
        $result = $this->run($project, '--filter=DiagnosticProbeTest', '--fail-on-notice');
        expect($result->exitCode)->because('diagnostic policies change passed tests to failed')->toBe(1);
        expect($result->output())->toContain('notice policy changed this test from passed to failed')
            ->toContain('a probe notice');
        $result = $this->run($project, '--filter=DiagnosticProbeTest', '--fail-on-warning');
        expect($result->exitCode)->because('diagnostic policies change passed tests to failed')->toBe(1);
        expect($result->output())->toContain('warning policy changed this test from passed to failed')
            ->toContain('a probe warning');
    }

    #[Test]
    public function riskyTestsWarnByDefaultAndFailUnderTheFlag(): void
    {
        $project = $this->writeProject();
        $result = $this->run($project, '--filter=RiskyProbeTest');
        $output = $result->output();
        $riskyBlock = \substr($output, (int) \strpos($output, 'Risky tests:'));
        expect($result->exitCode)->because('risky tests warn by default and fail under the flag')->toBe(0);
        expect($riskyBlock)
            ->toContain('Risky tests: 1')
            ->toContain('These tests passed without a verified expectation.')
            ->toContain('RiskyProbeTest::assertsNothing')
            ->not()->toContain('optedOut')
            ->not()->toContain('mocksOnly');

        // Only the mock verification adds to the count. Tests without an
        // expectation add nothing.
        expect($output)->toContain('1 expectation');
        $result = $this->run($project, '--filter=RiskyProbeTest', '--fail-on-risky');
        expect($result->exitCode)->because('risky tests warn by default and fail under the flag')->toBe(1);
        expect($result->output())->toContain('3 tests, 2 passed, 1 failed')
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

        expect($result->exitCode)
            ->because('the skipped policy MUST fail the run without changing skipped results')
            ->toBe(1);
        expect($result->output())
            ->toContain('SKIP PolicyProbe\SkipProbeTest::skips')
            ->toContain('2 tests, 1 passed, 1 skipped')
            ->toContain('integration service is unavailable')
            ->toContain('fail-on-skipped policy found 1 skipped test');

        $junit = (string) \file_get_contents($project->path('reports/junit.xml'));
        expect($junit)
            ->because('JUnit MUST retain the skipped testcase')
            ->toContain('failures="0"')
            ->toContain('skipped="1"')
            ->toContain(
                '<skipped message="integration service is unavailable">'
                . 'integration service is unavailable</skipped>',
            );

        $jsonl = (string) \file_get_contents($project->path('reports/events.jsonl'));
        expect($jsonl)
            ->because('JSONL MUST retain skipped result and summary fields')
            ->toContain('"outcome":"skipped"')
            ->toContain('"summary":{"passed":1,"failed":0,"errored":0,"skipped":1}');

        $teamCity = (string) \file_get_contents($project->path('reports/teamcity.txt'));
        expect($teamCity)
            ->because('TeamCity MUST retain its ignored-test message')
            ->toContain("##teamcity[testIgnored name='PolicyProbe\\SkipProbeTest::skips' message='integration service is unavailable'");

        expect((string) \file_get_contents($project->path('reports/github.txt')))
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

        expect($parallel->exitCode)
            ->because('the run policy MUST use the final process-pool summary')
            ->toBe(1);
        expect($parallel->output())->toContain('2 tests, 1 passed, 1 skipped');

        $repeated = $this->run(
            $project,
            '--filter=SkipProbeTest',
            '--fail-on-skipped',
            '--repeat=2',
        );
        expect($repeated->exitCode)
            ->because('each iteration with a skipped test MUST fail repeat mode')
            ->toBe(1);
        expect($repeated->output())->toContain('Repeat: failed iterations: 1, 2');
    }

    #[Test]
    public function retriedPassEvidenceAndPolicyStayConsistentAcrossConsumers(): void
    {
        $project = $this->writeProject();
        $result = GreenlightCli::run($project->directory, [
            'run',
            '--workers=2',
            '--shard=1/1',
            '--bail=1',
            '--filter=RetryProbeTest',
            '--reporter=plain',
            '--reporter=junit=reports/retry-junit.xml',
            '--reporter=jsonl=reports/retry-events.jsonl',
            '--reporter=teamcity=reports/retry-teamcity.txt',
            '--reporter=github=reports/retry-github.txt',
        ]);

        expect($result->exitCode)
            ->because('a retried pass MUST keep the default run successful')
            ->toBe(0);
        expect($result->output())
            ->toContain('PASS PolicyProbe\RetryProbeTest::passesAfterRetry')
            ->toContain('(passed after 2 attempts)')
            ->toContain('2 tests, 2 passed, 1 passed after retry')
            ->toContain('These results are evidence of instability.')
            ->toContain('PolicyProbe\RetryProbeTest::stillRuns');

        $junit = (string) \file_get_contents($project->path('reports/retry-junit.xml'));
        expect($junit)
            ->because('JUnit MUST retain a passed testcase and interoperable retry metadata')
            ->toContain('failures="0"')
            ->toContain('<flakyFailure type="retry" message="Passed after 2 attempts.">')
            ->toContain('[[ATTACHMENT|');

        $jsonl = (string) \file_get_contents($project->path('reports/retry-events.jsonl'));
        expect($jsonl)
            ->because('JSONL MUST use its existing result fields for retry evidence')
            ->toContain('"outcome":"passed"')
            ->toContain('"attempts":2')
            ->toContain('"attempt":1');

        expect((string) \file_get_contents($project->path('reports/retry-teamcity.txt')))
            ->because('TeamCity MUST receive numeric attempt metadata and failed-attempt attachments')
            ->toContain("name='greenlight.attempts' type='number' value='2'")
            ->toContain("name='attachment: attempt.txt'");

        expect((string) \file_get_contents($project->path('reports/retry-github.txt')))
            ->because('GitHub MUST receive a warning without a failure annotation')
            ->toContain('::warning title=Passed after retry::')
            ->toContain('passed after 2 attempts')
            ->not()->toContain('::error');

        $strict = $this->run(
            $project,
            '--workers=2',
            '--filter=RetryProbeTest',
            '--fail-on-retried-pass',
        );
        expect($strict->exitCode)
            ->because('the retried-pass run policy MUST fail without changing the passed result')
            ->toBe(1);
        expect($strict->output())
            ->toContain('2 tests, 2 passed, 1 passed after retry')
            ->toContain('fail-on-retried-pass policy found 1 test that passed after retry');

        $repeated = $this->run(
            $project,
            '--filter=RetryProbeTest',
            '--fail-on-retried-pass',
            '--repeat=2',
        );
        expect($repeated->exitCode)
            ->because('each iteration with a retried pass MUST fail repeat mode')
            ->toBe(1);
        expect($repeated->output())->toContain('Repeat: failed iterations: 1, 2');
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

            use function Greenlight\expect;

            final class DiagnosticProbeTest
            {
                #[Test]
                public function triggersDeprecation(): void
                {
                    \trigger_error('old api is deprecated', \E_USER_DEPRECATED);
                    expect(true)->toBeTrue();
                }

                #[Test]
                public function ignorableDeprecation(): void
                {
                    \trigger_error('vendor noise: legacy shim', \E_USER_DEPRECATED);
                    expect(true)->toBeTrue();
                }

                #[Test]
                public function triggersNotice(): void
                {
                    \trigger_error('a probe notice', \E_USER_NOTICE);
                    expect(true)->toBeTrue();
                }

                #[Test]
                public function triggersWarning(): void
                {
                    \trigger_error('a probe warning', \E_USER_WARNING);
                    expect(true)->toBeTrue();
                }
            }
            PHP);

        $project->writeFile('tests/SkipProbeTest.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace PolicyProbe;

            use Greenlight\Attribute\Test;
            use Greenlight\Test\SkipTest;

            use function Greenlight\expect;

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
                    expect(true)->toBeTrue();
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

        $project->writeFile('tests/RetryProbeTest.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace PolicyProbe;

            use Greenlight\Artifact\Attachments;
            use Greenlight\Attribute\Retry;
            use Greenlight\Attribute\Test;

            use function Greenlight\expect;

            final readonly class RetryProbeTest
            {
                public function __construct(private Attachments $attachments) {}

                #[Test]
                #[Retry(1)]
                public function passesAfterRetry(): void
                {
                    static $attempt = 0;
                    ++$attempt;
                    $this->attachments->text('attempt.txt', 'attempt ' . $attempt);

                    if ($attempt % 2 === 1) {
                        throw new \RuntimeException('retry this attempt');
                    }

                    expect(true)->toBeTrue();
                }

                #[Test]
                public function stillRuns(): void
                {
                    expect(true)->toBeTrue();
                }
            }
            PHP);

        $project->writeFile('greenlight.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            use Greenlight\Config\GreenlightConfig;

            require_once __DIR__ . '/tests/DiagnosticProbeTest.php';
            require_once __DIR__ . '/tests/RiskyProbeTest.php';
            require_once __DIR__ . '/tests/RetryProbeTest.php';
            require_once __DIR__ . '/tests/SkipProbeTest.php';

            return GreenlightConfig::create()
                ->paths([__DIR__ . '/tests'])
                ->ignoreDeprecationsMatching('vendor noise:')
                ->workers(1);
            PHP);

        return $project;
    }
}
