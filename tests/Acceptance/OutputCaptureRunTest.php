<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Event\TestFinished;
use Greenlight\Expect\Expect;
use Greenlight\Result\Outcome;
use Greenlight\Result\OutputCaptureCapability;
use Greenlight\Sandbox\TemporaryDirectory;
use Greenlight\Tests\Support\AcceptanceProject;
use Greenlight\Tests\Support\GreenlightCli;
use Greenlight\Tests\Support\JsonlEvents;
use Greenlight\Tests\Support\ProcessResult;

final readonly class OutputCaptureRunTest
{
    public function __construct(private TemporaryDirectory $tempDirectory) {}

    #[Test]
    #[DataSet('workerCounts')]
    public function directWritesRemainWithTheirTest(int $workers, OutputCaptureCapability $capability): void
    {
        $project = AcceptanceProject::create($this->tempDirectory, 'direct-output-' . $workers);
        $project->writeFile('tests/DirectOutputTest.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace DirectOutput;

            use Greenlight\Attribute\Test;

            final class DirectOutputTest
            {
                #[Test]
                public function writesToEachStream(): void
                {
                    \fwrite(\STDOUT, 'stdout-direct');
                    echo '-buffered';
                    \fwrite(\STDERR, "stderr-direct\xFF");
                }
            }
            PHP);
        $project->configureWithTestFiles(['tests/DirectOutputTest.php'], workers: $workers);

        $result = GreenlightCli::run($project->directory, ['run', '--reporter=jsonl']);
        $finished = \array_find(
            JsonlEvents::from($result),
            static fn($event): bool => $event instanceof TestFinished,
        );

        Expect::that($finished)
            ->because('The run did not emit a test result. Output: ' . $result->output())
            ->toBeInstanceOf(TestFinished::class);
        Expect::that($result->exitCode)->toBe(0);
        Expect::that($finished->result->output?->stdout)->toBe('stdout-direct-buffered');
        Expect::that($finished->result->output?->stderr)->toBe("stderr-direct\u{FFFD}");
        Expect::that($finished->result->output?->capability)->toBe($capability);
    }

    /** @return iterable<string, array{positive-int, OutputCaptureCapability}> */
    public static function workerCounts(): iterable
    {
        yield 'in-process' => [1, OutputCaptureCapability::PhpStreams];
        yield 'process pool' => [2, OutputCaptureCapability::ProcessDescriptors];
    }

    #[Test]
    #[DataSet('workerCounts')]
    public function failedAttemptOutputSurvivesAPassingRetry(int $workers, OutputCaptureCapability $capability): void
    {
        $project = AcceptanceProject::create($this->tempDirectory, 'retry-output-' . $workers);
        $project->writeFile('tests/RetryOutputTest.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace RetryOutput;

            use Greenlight\Attribute\Retry;
            use Greenlight\Attribute\Test;

            final class RetryOutputTest
            {
                #[Test]
                #[Retry(1)]
                public function passesOnRetry(): void
                {
                    static $attempt = 0;
                    ++$attempt;
                    \fwrite(\STDOUT, 'stdout-' . $attempt . "\n");
                    \fwrite(\STDERR, 'stderr-' . $attempt . "\n");

                    if ($attempt === 1) {
                        throw new \RuntimeException('retry');
                    }
                }
            }
            PHP);
        $project->configureWithTestFiles(['tests/RetryOutputTest.php'], workers: $workers);

        $result = GreenlightCli::run($project->directory, ['run', '--reporter=jsonl']);
        $finished = $this->finished($result);

        Expect::that($result->exitCode)->toBe(0);
        Expect::that($finished->result->outcome)->toBe(Outcome::Passed);
        Expect::that($finished->result->attempts)->toBe(2);
        Expect::that($finished->result->output?->stdout)->toBe("stdout-1\nstdout-2\n");
        Expect::that($finished->result->output?->stderr)->toBe("stderr-1\nstderr-2\n");
        Expect::that($finished->result->output?->capability)->toBe($capability);
    }

    #[Test]
    public function inheritedSubprocessWritesAreCapturedByTheProcessPool(): void
    {
        $project = AcceptanceProject::create($this->tempDirectory, 'inherited-output');
        $project->writeFile('tests/InheritedOutputTest.php', <<<'PHP_WRAP'
        <?php

        declare(strict_types=1);

        namespace InheritedOutput;

        use Greenlight\Attribute\Test;

        final class InheritedOutputTest
        {
            #[Test]
            public function startsAChildProcess(): void
            {
                $process = \proc_open(
                    [\PHP_BINARY, '-r', 'fwrite(STDOUT, "child-out"); fwrite(STDERR, "child-err");'],
                    [0 => ['pipe', 'r'], 1 => \STDOUT, 2 => \STDERR],
                    $pipes,
                );

                if (!\is_resource($process)) {
                    throw new \RuntimeException('The child process did not start.');
                }

                \fclose($pipes[0]);

                if (\proc_close($process) !== 0) {
                    throw new \RuntimeException('The child process failed.');
                }
            }
        }
        PHP_WRAP);
        $project->configureWithTestFiles(['tests/InheritedOutputTest.php'], workers: 2);

        $result = GreenlightCli::run($project->directory, ['run', '--reporter=jsonl']);
        $finished = $this->finished($result);

        Expect::that($result->exitCode)->toBe(0);
        Expect::that($finished->result->output?->stdout)->toBe('child-out');
        Expect::that($finished->result->output?->stderr)->toBe('child-err');
        Expect::that($finished->result->output?->capability)->toBe(OutputCaptureCapability::ProcessDescriptors);
    }

    #[Test]
    #[DataSet('workerCounts')]
    public function lifecycleWritesStayInsideTheAttemptBoundary(
        int $workers,
        OutputCaptureCapability $capability,
    ): void {
        $project = AcceptanceProject::create($this->tempDirectory, 'lifecycle-output-' . $workers);
        $project->writeFile('tests/LifecycleOutputTest.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace LifecycleOutput;

            use Greenlight\Attribute\After;
            use Greenlight\Attribute\Before;
            use Greenlight\Attribute\Test;
            use Greenlight\Harness\Disposable;
            use Greenlight\Harness\Scope;
            use Greenlight\Harness\ServiceDefinition;
            use Greenlight\Plugin\AfterTestSubscriber;
            use Greenlight\Plugin\BeforeTestSubscriber;
            use Greenlight\Plugin\HarnessProvider;
            use Greenlight\Plugin\TestAttemptRunner;
            use Greenlight\Plugin\TestContext;
            use Greenlight\Result\TestResult;
            use Greenlight\Test\Cleanup;

            final class LifecyclePlugin implements AfterTestSubscriber, BeforeTestSubscriber, HarnessProvider, TestAttemptRunner
            {
                public function services(): array
                {
                    return [new ServiceDefinition(
                        ClassService::class,
                        Scope::PerClass,
                        static fn(): ClassService => new ClassService(),
                    )];
                }

                public function runTestAttempt(\Closure $attempt): mixed
                {
                    \fwrite(\STDOUT, "runner-before\n");

                    try {
                        return $attempt();
                    } finally {
                        \fwrite(\STDOUT, "runner-after\n");
                    }
                }

                public function beforeTest(TestContext $context): void
                {
                    \fwrite(\STDOUT, "before-subscriber\n");
                }

                public function afterTest(TestContext $context, TestResult $result): TestResult
                {
                    \fwrite(\STDOUT, "after-subscriber\n");

                    return $result;
                }
            }

            final class ClassService implements Disposable
            {
                public function touch(): void {}

                public function dispose(): void
                {
                    \fwrite(\STDOUT, "class-scope-disposal\n");
                }
            }

            final readonly class LifecycleOutputTest
            {
                public function __construct(ClassService $service, Cleanup $cleanup)
                {
                    $service->touch();
                    \fwrite(\STDOUT, "constructor\n");
                    $cleanup->defer(static fn() => \fwrite(\STDOUT, "cleanup\n"));
                }

                #[Before]
                public function before(): void
                {
                    \fwrite(\STDOUT, "before-hook\n");
                }

                #[After]
                public function after(): void
                {
                    \fwrite(\STDOUT, "after-hook\n");
                }

                #[Test]
                public function writes(): void
                {
                    \fwrite(\STDOUT, "test-body\n");
                }
            }
            PHP);
        $project->writeFile('greenlight.php', \sprintf(
            <<<'PHP'
            <?php

            declare(strict_types=1);

            use Greenlight\Config\GreenlightConfig;
            use LifecycleOutput\LifecyclePlugin;

            require_once __DIR__ . '/tests/LifecycleOutputTest.php';

            return GreenlightConfig::create()
                ->paths([__DIR__ . '/tests'])
                ->workers(%d)
                ->plugins(static fn(): LifecyclePlugin => new LifecyclePlugin());
            PHP,
            $workers,
        ));

        $result = GreenlightCli::run($project->directory, ['run', '--reporter=jsonl']);
        $finished = $this->finished($result);

        Expect::that($result->exitCode)->toBe(0);
        Expect::that($finished->result->output?->stdout)->toBe(<<<'OUTPUT'
            runner-before
            constructor
            before-subscriber
            before-hook
            test-body
            after-hook
            cleanup
            after-subscriber
            runner-after
            class-scope-disposal

            OUTPUT);
        Expect::that($finished->result->output?->capability)->toBe($capability);
    }

    #[Test]
    #[DataSet('workerNumbers')]
    public function disabledCaptureDoesNotRetainOrLeakDirectWrites(int $workers): void
    {
        $project = AcceptanceProject::create($this->tempDirectory, 'disabled-output-' . $workers);
        $project->writeFile('tests/DisabledOutputTest.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace DisabledOutput;

            use Greenlight\Attribute\Test;

            final class DisabledOutputTest
            {
                #[Test(capture: false)]
                public function writesDirectly(): void
                {
                    \fwrite(\STDOUT, 'discarded-stdout');
                    \fwrite(\STDERR, 'discarded-stderr');
                }
            }
            PHP);
        $project->configureWithTestFiles(['tests/DisabledOutputTest.php'], workers: $workers);

        $result = GreenlightCli::run($project->directory, ['run', '--reporter=jsonl']);
        $finished = $this->finished($result);

        Expect::that($result->exitCode)->toBe(0);
        Expect::that($finished->result->output)->toBeNull();
        Expect::that($result->stdout)->not()->toContain('discarded-stdout');
        Expect::that($result->stderr)->not()->toContain('discarded-stderr');
    }

    /** @return iterable<string, array{positive-int}> */
    public static function workerNumbers(): iterable
    {
        yield 'in-process' => [1];
        yield 'process pool' => [2];
    }

    private function finished(ProcessResult $result): TestFinished
    {
        $finished = \array_find(
            JsonlEvents::from($result),
            static fn($event): bool => $event instanceof TestFinished,
        );

        if (!$finished instanceof TestFinished) {
            throw new \RuntimeException('The run did not emit a test result. Output: ' . $result->output());
        }

        return $finished;
    }
}
