<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Fixture\TempDirectory;
use Greenlight\Tests\Support\AcceptanceProject;
use Greenlight\Tests\Support\GreenlightCli;
use Greenlight\Tests\Support\ProcessResult;

/**
 * The CI gates through the real CLI.
 *
 * Deprecation and notice policies flip passed tests to failed with the
 * diagnostic as the detail, and the allow-list exempts matched deprecations.
 *
 * Risky tests warn by default and fail under the flag, while both the
 * doubles-only test and the #[NoExpectations] opt-out stay quiet.
 */
final readonly class PolicyTest
{
    public function __construct(private TempDirectory $tempDirectory) {}

    #[Test]
    public function deprecationAndNoticePoliciesFlipPassedTests(): void
    {
        $project = $this->writeProject();
        // Without flags everything passes; deprecations are recorded, not fatal.
        $result = $this->run($project, '--filter=DiagnosticProbeTest');
        Expect::that($result->exitCode)->toBe(0)
            ->and($result->output())->toContain('3 tests, 3 passed')
            // One matcher per test crossed the worker boundary into the summary.
            ->toContain('3 expectations');
        $result = $this->run($project, '--filter=DiagnosticProbeTest', '--fail-on-deprecation');
        Expect::that($result->exitCode)->toBe(1)
            ->and($result->output())->toContain('3 tests, 2 passed, 1 failed')
            ->toContain('deprecation policy failed this passed test')
            ->toContain('old api is deprecated')
            // The flip must not drop the flipped test's verified expectations.
            ->toContain('3 expectations')
            // The allow-listed deprecation stays green.
            ->toContain('PASS PolicyProbe\DiagnosticProbeTest::ignorableDeprecation');
        $result = $this->run($project, '--filter=DiagnosticProbeTest', '--fail-on-notice');
        Expect::that($result->exitCode)->toBe(1)
            ->and($result->output())->toContain('notice policy failed this passed test')
            ->toContain('a probe notice');
    }

    #[Test]
    public function riskyTestsWarnByDefaultAndFailUnderTheFlag(): void
    {
        $project = $this->writeProject();
        $result = $this->run($project, '--filter=RiskyProbeTest');
        $output = $result->output();
        $riskyBlock = \substr($output, (int) \strpos($output, 'Risky:'));
        Expect::that($result->exitCode)->toBe(0)
            ->and($riskyBlock)->toContain('Risky: 1 passed without verifying any expectation')
            ->toContain('RiskyProbeTest::assertsNothing')
            ->not()->toContain('optedOut')
            ->not()->toContain('mocksOnly')
            // Only the mock verification counts; the empty tests add nothing.
            ->and($output)->toContain('1 expectation');
        $result = $this->run($project, '--filter=RiskyProbeTest', '--fail-on-risky');
        Expect::that($result->exitCode)->toBe(1)
            ->and($result->output())->toContain('3 tests, 2 passed, 1 failed')
            ->toContain('fail-on-risky policy failed this passed test');
    }

    private function run(AcceptanceProject $project, string ...$flags): ProcessResult
    {
        return GreenlightCli::run($project->directory, \array_values(['run', '--reporter=plain', ...$flags]));
    }

    private function writeProject(): AcceptanceProject
    {
        $project = AcceptanceProject::create($this->tempDirectory, 'policy');

        $project->write('tests/DiagnosticProbeTest.php', <<<'PHP'
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
            }
            PHP);

        $project->write('tests/RiskyProbeTest.php', <<<'PHP'
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

        $project->write('greenlight.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            use Greenlight\Config\GreenlightConfig;

            require_once __DIR__ . '/tests/DiagnosticProbeTest.php';
            require_once __DIR__ . '/tests/RiskyProbeTest.php';

            return GreenlightConfig::create()
                ->paths([__DIR__ . '/tests'])
                ->ignoreDeprecationsMatching('vendor noise:')
                ->workers(1);
            PHP);

        return $project;
    }
}
