<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Sandbox\TemporaryDirectory;
use Greenlight\Tests\Support\AcceptanceProject;
use Greenlight\Tests\Support\GreenlightCli;

final readonly class TestAttemptRunnerRunTest
{
    public function __construct(private TemporaryDirectory $tempDirectory) {}

    #[Test]
    public function runtimeBoundaryContainsTheCompleteTestAttempt(): void
    {
        $project = AcceptanceProject::create($this->tempDirectory, 'test-attempt-runner');
        $project->writeFile('tests/RuntimeBoundaryTest.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace RuntimeBoundaryProbe;

            use Greenlight\Attribute\After;
            use Greenlight\Attribute\Before;
            use Greenlight\Attribute\Test;
            use Greenlight\Core\Result\TestResult;
            use Greenlight\Expect\Expect;
            use Greenlight\Harness\Disposable;
            use Greenlight\Harness\Scope;
            use Greenlight\Harness\ServiceDefinition;
            use Greenlight\Plugin\AfterTestSubscriber;
            use Greenlight\Plugin\BeforeTestSubscriber;
            use Greenlight\Plugin\HarnessProvider;
            use Greenlight\Plugin\TestAttemptRunner;
            use Greenlight\Plugin\TestContext;

            final class Boundary
            {
                public static bool $active = false;

                /** @var list<string> */
                public static array $events = [];

                public static function record(string $event): void
                {
                    if (!self::$active) {
                        throw new \RuntimeException('The test attempt ran outside the runtime boundary.');
                    }

                    self::$events[] = $event;
                }
            }

            final class RecordingDisposable implements Disposable
            {
                public function dispose(): void
                {
                    Boundary::record('dispose');
                }
            }

            final class RuntimePlugin implements AfterTestSubscriber, BeforeTestSubscriber, HarnessProvider, TestAttemptRunner
            {
                public function services(): array
                {
                    return [new ServiceDefinition(
                        RecordingDisposable::class,
                        Scope::PerTest,
                        static fn(): RecordingDisposable => new RecordingDisposable(),
                    )];
                }

                public function runTestAttempt(\Closure $attempt): mixed
                {
                    Boundary::$active = true;

                    try {
                        return $attempt();
                    } finally {
                        Boundary::$events[] = 'exit';
                        Boundary::$active = false;
                    }
                }

                public function beforeTest(TestContext $context): void
                {
                    Boundary::record('beforeTest');
                }

                public function afterTest(TestContext $context, TestResult $result): TestResult
                {
                    Boundary::record('afterTest');

                    return $result;
                }
            }

            final readonly class RuntimeBoundaryTest
            {
                public function __construct(private RecordingDisposable $disposable)
                {
                    Boundary::record('constructor');
                }

                #[Before]
                public function before(): void
                {
                    Boundary::record('before');
                }

                #[After]
                public function after(): void
                {
                    Boundary::record('after');
                }

                #[Test]
                public function firstAttemptStaysInsideTheBoundary(): void
                {
                    Boundary::record('test');
                    Expect::that(Boundary::$active)->toBeTrue();
                }

                #[Test]
                public function nextAttemptSeesTheCompletedFirstBoundary(): void
                {
                    Expect::that(Boundary::$events)->toBe([
                        'constructor',
                        'beforeTest',
                        'before',
                        'test',
                        'after',
                        'dispose',
                        'afterTest',
                        'exit',
                        'constructor',
                        'beforeTest',
                        'before',
                    ]);
                }
            }
            PHP);
        $project->writeFile('greenlight.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            use Greenlight\Config\GreenlightConfig;
            use RuntimeBoundaryProbe\RuntimePlugin;

            require_once __DIR__ . '/tests/RuntimeBoundaryTest.php';

            return GreenlightConfig::create()
                ->paths([__DIR__ . '/tests'])
                ->workers(1)
                ->plugins(new RuntimePlugin());
            PHP);

        $result = GreenlightCli::run($project->directory, ['run', '--reporter=plain']);

        $output = $result->output();
        Expect::that($result->exitCode)
            ->because($output === '' ? 'The subprocess returned no output.' : $output)
            ->toBe(0);
        Expect::that($result->output())->toContain('2 tests, 2 passed');
    }

    #[Test]
    public function runtimeBoundaryFailureErrorsTheAttempt(): void
    {
        $project = AcceptanceProject::create($this->tempDirectory, 'test-attempt-runner-failure');
        $project->writeFile('tests/BoundaryFailureTest.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace RuntimeBoundaryFailureProbe;

            use Greenlight\Attribute\Test;
            use Greenlight\Plugin\TestAttemptRunner;

            final class FailingRuntimePlugin implements TestAttemptRunner
            {
                public function runTestAttempt(\Closure $attempt): mixed
                {
                    throw new \RuntimeException('The attempt runtime failed before the test started.');
                }
            }

            final readonly class BoundaryFailureTest
            {
                #[Test]
                public function doesNotRunOutsideTheFailedBoundary(): void
                {
                    throw new \RuntimeException('The test method must not run.');
                }
            }
            PHP);
        $project->writeFile('greenlight.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            use Greenlight\Config\GreenlightConfig;
            use RuntimeBoundaryFailureProbe\FailingRuntimePlugin;

            require_once __DIR__ . '/tests/BoundaryFailureTest.php';

            return GreenlightConfig::create()
                ->paths([__DIR__ . '/tests'])
                ->workers(1)
                ->plugins(new FailingRuntimePlugin());
            PHP);

        $result = GreenlightCli::run($project->directory, ['run', '--reporter=plain']);

        Expect::that($result->exitCode)->toBe(1);
        Expect::that($result->output())
            ->toContain('The attempt runtime failed before the test started.')
            ->toContain('1 test, 0 passed, 1 errored');
    }
}
