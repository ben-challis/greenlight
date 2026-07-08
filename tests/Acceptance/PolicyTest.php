<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;

/**
 * The CI gates through the real CLI.
 *
 * Deprecation and notice policies flip passed tests to failed with the
 * diagnostic as the detail, and the allow-list exempts matched deprecations.
 *
 * Risky tests warn by default and fail under the flag, while both the
 * doubles-only test and the #[NoExpectations] opt-out stay quiet.
 */
final class PolicyTest
{
    #[Test]
    public function deprecationAndNoticePoliciesFlipPassedTests(): void
    {
        $project = $this->writeProject();

        try {
            // Without flags everything passes; deprecations are recorded, not fatal.
            [$exit, $output] = $this->run($project, '--filter=DiagnosticProbeTest');
            Expect::that($exit)->toBe(0)
                ->and($output)->toContain('3 tests, 3 passed')
                // One matcher per test crossed the worker boundary into the summary.
                ->and($output)->toContain('3 expectations');

            [$exit, $output] = $this->run($project, '--filter=DiagnosticProbeTest', '--fail-on-deprecation');
            Expect::that($exit)->toBe(1)
                ->and($output)->toContain('3 tests, 2 passed, 1 failed')
                ->and($output)->toContain('deprecation policy failed this passed test')
                ->and($output)->toContain('old api is deprecated')
                // The allow-listed deprecation stays green.
                ->and($output)->toContain('PASS PolicyProbe\DiagnosticProbeTest::ignorableDeprecation');

            [$exit, $output] = $this->run($project, '--filter=DiagnosticProbeTest', '--fail-on-notice');
            Expect::that($exit)->toBe(1)
                ->and($output)->toContain('notice policy failed this passed test')
                ->and($output)->toContain('a probe notice');
        } finally {
            $this->removeTree($project);
        }
    }

    #[Test]
    public function riskyTestsWarnByDefaultAndFailUnderTheFlag(): void
    {
        $project = $this->writeProject();

        try {
            [$exit, $output] = $this->run($project, '--filter=RiskyProbeTest');
            $riskyBlock = \substr($output, (int) \strpos($output, 'Risky:'));
            Expect::that($exit)->toBe(0)
                ->and($riskyBlock)->toContain('Risky: 1 passed without verifying any expectation')
                ->and($riskyBlock)->toContain('RiskyProbeTest::assertsNothing')
                ->and($riskyBlock)->not()->toContain('optedOut')
                ->and($riskyBlock)->not()->toContain('mocksOnly')
                // Only the mock verification counts; the empty tests add nothing.
                ->and($output)->toContain('1 expectation');

            [$exit, $output] = $this->run($project, '--filter=RiskyProbeTest', '--fail-on-risky');
            Expect::that($exit)->toBe(1)
                ->and($output)->toContain('3 tests, 2 passed, 1 failed')
                ->and($output)->toContain('fail-on-risky policy failed this passed test');
        } finally {
            $this->removeTree($project);
        }
    }

    /**
     * @return array{int, string}
     */
    private function run(string $project, string ...$flags): array
    {
        $root = \dirname(__DIR__, 2);
        $parts = [\escapeshellarg(\PHP_BINARY), \escapeshellarg($root . '/bin/greenlight'), 'run', '--reporter=plain'];

        foreach ($flags as $flag) {
            $parts[] = \escapeshellarg($flag);
        }

        $command = \sprintf('cd %s && %s 2>&1', \escapeshellarg($project), \implode(' ', $parts));
        \exec($command, $output, $exit);

        return [$exit, \implode("\n", $output)];
    }

    private function writeProject(): string
    {
        $project = \sys_get_temp_dir() . '/greenlight-policy-' . \bin2hex(\random_bytes(6));
        \mkdir($project . '/tests', 0o777, true);

        \file_put_contents($project . '/tests/DiagnosticProbeTest.php', <<<'PHP'
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

        \file_put_contents($project . '/tests/RiskyProbeTest.php', <<<'PHP'
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

        \file_put_contents($project . '/greenlight.php', <<<'PHP'
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

    private function removeTree(string $directory): void
    {
        $files = \glob($directory . '/tests/*');

        foreach (\is_array($files) ? $files : [] as $file) {
            @\unlink($file);
        }

        @\unlink($directory . '/greenlight.php');
        @\rmdir($directory . '/tests');
        @\rmdir($directory);
    }
}
