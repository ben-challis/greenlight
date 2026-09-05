<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\DataRow;
use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Sandbox\TemporaryDirectory;
use Greenlight\Tests\Support\AcceptanceProject;
use Greenlight\Tests\Support\GreenlightCli;
use Greenlight\Tests\Support\PhpSubprocess;

final readonly class AmpDeadlineRunTest
{
    public function __construct(private TemporaryDirectory $tempDirectory) {}

    #[Test]
    public function synchronousExpectationsWorkWithoutTheOptionalRuntime(): void
    {
        $root = \dirname(__DIR__, 2);
        $result = PhpSubprocess::run($root, ['-r', <<<'PHP'
            spl_autoload_register(static function (string $class): void {
                if (str_starts_with($class, 'Greenlight\\')) {
                    $file = getcwd() . '/src/' . str_replace('\\', '/', substr($class, strlen('Greenlight\\'))) . '.php';

                    if (is_file($file)) {
                        require $file;
                    }
                }
            });

            Greenlight\Expect\Expect::eventually(static fn(): bool => true)->within(0.010)->toBeTrue();

            try {
                (new Greenlight\Amp\AmpPlugin())->runTestAttempt(static fn(): bool => true);
                exit(2);
            } catch (RuntimeException $failure) {
                echo $failure->getMessage();
            }
            PHP]);

        Expect::that($result->exitCode)->toBe(0);
        Expect::that($result->output())->toContain('AmpPlugin requires amphp/amp ^3.1 and revolt/event-loop ^1.0.');
    }

    #[Test]
    #[DataRow(['constructor'])]
    #[DataRow(['subscriber'])]
    #[DataRow(['before'])]
    #[DataRow(['body'])]
    #[DataRow(['after'])]
    #[DataRow(['cleanup'])]
    #[DataRow(['dispose'])]
    public function supportedWaitsFailAtTheDeadlineAcrossTheLifecycle(string $phase): void
    {
        $project = $this->project('amp-lifecycle-' . $phase, \str_replace('__PHASE__', $phase, <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace AmpLifecycleProbe;

            use Greenlight\Amp\AmpContext;
            use Greenlight\Attribute\After;
            use Greenlight\Attribute\Before;
            use Greenlight\Attribute\Test;
            use Greenlight\Attribute\Timeout;
            use Greenlight\Expect\Expect;
            use Greenlight\Harness\Disposable;
            use Greenlight\Harness\Scope;
            use Greenlight\Harness\ServiceDefinition;
            use Greenlight\Plugin\BeforeTestSubscriber;
            use Greenlight\Plugin\HarnessProvider;
            use Greenlight\Plugin\TestContext;
            use Greenlight\Test\Cleanup;

            final class Trace
            {
                public static function record(string $phase): void
                {
                    \file_put_contents(__DIR__ . '/../trace', $phase . "\n", FILE_APPEND);

                    if ($phase === '__PHASE__') {
                        AmpContext::delay(10.0);
                        \file_put_contents(__DIR__ . '/../trace', "overran\n", FILE_APPEND);
                    }
                }
            }

            final class Resource implements Disposable
            {
                public function touch(): void {}

                public function dispose(): void
                {
                    Trace::record('dispose');
                }
            }

            final class FixturePlugin implements BeforeTestSubscriber, HarnessProvider
            {
                public function beforeTest(TestContext $context): void
                {
                    Trace::record('subscriber');
                }

                public function services(): array
                {
                    return [new ServiceDefinition(Resource::class, Scope::PerTest, static fn() => new Resource())];
                }
            }

            final class DeadlineTest
            {
                public function __construct(Cleanup $cleanup, Resource $resource)
                {
                    $cleanup->defer(static fn() => Trace::record('cleanup'));
                    $resource->touch();
                    Trace::record('constructor');
                }

                #[Before]
                public function before(): void
                {
                    Trace::record('before');
                }

                #[After]
                public function after(): void
                {
                    Trace::record('after');
                }

                #[Test]
                #[Timeout(0.100)]
                public function stops(): void
                {
                    Trace::record('body');
                    Expect::that(true)->toBeTrue();
                }
            }
            PHP), 'static fn(): \AmpLifecycleProbe\FixturePlugin => new \AmpLifecycleProbe\FixturePlugin(),');

        $result = GreenlightCli::run($project->directory, ['run', '--reporter=plain']);

        $output = $result->output();
        Expect::that($result->exitCode)->because($output === '' ? 'The subprocess returned no output.' : $output)->toBe(1);
        Expect::that($result->output())->toContain('1 failed')->not()->toContain('1 errored');
        Expect::that($result->output())->toContain('test time limit stopped an asynchronous operation');
        $trace = (string) \file_get_contents($project->path('trace'));
        Expect::that($trace)->not()->toContain('overran');
        Expect::that(\substr_count($trace, "cleanup\n"))->toBe(1);
        Expect::that(\substr_count($trace, "dispose\n"))->toBe(1);
        Expect::that(\substr_count($trace, "after\n"))->toBe($phase === 'constructor' ? 0 : 1);
    }

    #[Test]
    public function aTemporalDeadlineCancelsAProbeWithoutRetryingItsCancellation(): void
    {
        $project = $this->project('amp-temporal', <<<'PHP'
            <?php

            declare(strict_types=1);

            use Greenlight\Amp\AmpContext;
            use Greenlight\Attribute\Test;
            use Greenlight\Attribute\Timeout;
            use Greenlight\Expect\Expect;

            final class DeadlineTest
            {
                #[Test]
                #[Timeout(2.0)]
                public function stopsTheProbe(): void
                {
                    Expect::eventually(static function (): bool {
                        \file_put_contents(__DIR__ . '/../trace', "probe\n", FILE_APPEND);
                        AmpContext::delay(10.0);

                        return true;
                    })->retryOnException(\Exception::class)->within(0.050)->toBeTrue();
                }
            }
            PHP);

        $result = GreenlightCli::run($project->directory, ['run', '--reporter=plain']);

        Expect::that($result->exitCode)->toBe(1);
        Expect::that($result->output())->toContain('temporal expectation time limit')->toContain('1 failed');
        Expect::that($result->output())->not()->toContain('test time limit');
        Expect::that(\file_get_contents($project->path('trace')))->toBe("probe\n");
    }

    #[Test]
    public function retriesJoinChildrenBeforeCleanupAndReceiveAFreshDeadline(): void
    {
        $project = $this->project('amp-retry', <<<'PHP'
            <?php

            declare(strict_types=1);

            use Greenlight\Amp\AmpContext;
            use Greenlight\Attribute\After;
            use Greenlight\Attribute\Retry;
            use Greenlight\Attribute\Test;
            use Greenlight\Attribute\Timeout;
            use Greenlight\Expect\Expect;
            use Greenlight\Test\Cleanup;

            final class DeadlineTest
            {
                private static int $attempt = 0;
                private static bool $activeChild = false;

                public function __construct(Cleanup $cleanup)
                {
                    ++self::$attempt;
                    Expect::that(self::$activeChild)->toBeFalse();
                    self::record('construct');
                    $cleanup->defer(static fn() => self::record('cleanup'));
                }

                #[After]
                public function after(): void
                {
                    Expect::that(self::$activeChild)->toBeFalse();
                    self::record('after');
                }

                #[Test]
                #[Retry(1)]
                #[Timeout(0.150)]
                public function completesTheSecondAttempt(): void
                {
                    if (self::$attempt === 2) {
                        AmpContext::delay(0.020);
                        Expect::that(self::$activeChild)->toBeFalse();
                        self::record('pass');

                        return;
                    }

                    AmpContext::async(static function (): void {
                        self::$activeChild = true;
                        self::record('child');

                        try {
                            AmpContext::delay(10.0);
                        } finally {
                            // This uncancelled wait proves that the join waits for child cleanup.
                            \Amp\delay(0.020);
                            self::$activeChild = false;
                            self::record('joined');
                        }
                    });
                    AmpContext::delay(10.0);
                }

                private static function record(string $phase): void
                {
                    \file_put_contents(__DIR__ . '/../trace', self::$attempt . ':' . $phase . "\n", FILE_APPEND);
                }
            }
            PHP);

        $result = GreenlightCli::run($project->directory, ['run', '--reporter=plain']);

        $output = $result->output();
        Expect::that($result->exitCode)->because($output === '' ? 'The subprocess returned no output.' : $output)->toBe(0);
        Expect::that($result->output())->toContain('1 passed');
        Expect::that(\file_get_contents($project->path('trace')))->toBe(
            "1:construct\n1:child\n1:joined\n1:after\n1:cleanup\n2:construct\n2:pass\n2:after\n2:cleanup\n",
        );
    }

    #[Test]
    public function cleanupCancellationKeepsAnEarlierFailureAndRunsRemainingCallbacks(): void
    {
        $project = $this->project('amp-cleanup-failure', <<<'PHP'
            <?php

            declare(strict_types=1);

            use Greenlight\Amp\AmpContext;
            use Greenlight\Attribute\Test;
            use Greenlight\Attribute\Timeout;
            use Greenlight\Expect\Fail;
            use Greenlight\Test\Cleanup;

            final class DeadlineTest
            {
                public function __construct(Cleanup $cleanup)
                {
                    $cleanup->defer(static fn() => \file_put_contents(__DIR__ . '/../trace', 'closed'));
                    $cleanup->defer(static fn() => AmpContext::delay(10.0));
                }

                #[Test]
                #[Timeout(0.100)]
                public function failsBeforeCleanup(): void
                {
                    Fail::because('The original assertion failed.');
                }
            }
            PHP);

        $result = GreenlightCli::run($project->directory, ['run', '--reporter=plain']);

        Expect::that($result->exitCode)->toBe(1);
        Expect::that($result->output())->toContain('The original assertion failed.')
            ->toContain('test time limit stopped an asynchronous operation')->toContain('1 failed');
        Expect::that(\file_get_contents($project->path('trace')))->toBe('closed');
    }

    #[Test]
    public function processEnforcementStillContainsAChildThatIgnoresCancellation(): void
    {
        $project = $this->project('amp-process-timeout', <<<'PHP'
            <?php

            declare(strict_types=1);

            use Greenlight\Amp\AmpContext;
            use Greenlight\Attribute\Test;
            use Greenlight\Attribute\Timeout;
            use Greenlight\Expect\Expect;

            final class DeadlineTest
            {
                #[Test]
                #[Timeout(0.050)]
                public function blocks(): void
                {
                    AmpContext::async(static fn() => \sleep(10));
                    AmpContext::delay(10.0);
                }

                #[Test]
                public function recoversAfterTheBlockedTest(): void
                {
                    Expect::that(true)->toBeTrue();
                }
            }
            PHP);

        $result = GreenlightCli::run($project->directory, ['run', '--workers=2', '--reporter=plain']);

        Expect::that($result->exitCode)->toBe(1);
        Expect::that($result->output())->toContain('exceeded its 0.050-second time limit');
        Expect::that($result->output())->toContain('1 passed')->toContain('1 failed');
    }

    private function project(string $name, string $source, string $extraPlugin = ''): AcceptanceProject
    {
        $project = AcceptanceProject::create($this->tempDirectory, $name);
        $project->writeFile('tests/DeadlineTest.php', $source);
        $project->writeFile('greenlight.php', \str_replace('__EXTRA_PLUGIN__', $extraPlugin, <<<'PHP'
            <?php

            declare(strict_types=1);

            use Greenlight\Amp\AmpPlugin;
            use Greenlight\Config\GreenlightConfig;

            require_once __DIR__ . '/tests/DeadlineTest.php';

            return GreenlightConfig::create()
                ->paths([__DIR__ . '/tests'])
                ->workers(1)
                ->plugins(
                    static fn(): AmpPlugin => new AmpPlugin(),
                    __EXTRA_PLUGIN__
                );
            PHP));

        return $project;
    }
}
