<?php

declare(strict_types=1);

namespace Greenlight\Execution\Plugin;

use Greenlight\Expect\Expect;
use Greenlight\Expect\ExpectationExtension;
use Greenlight\Harness\HarnessScopes;
use Greenlight\Harness\ServiceDefinition;
use Greenlight\Harness\ServiceResolver;
use Greenlight\Harness\TerminalServiceResolver;
use Greenlight\Plugin\AfterTestSubscriber;
use Greenlight\Plugin\BeforeTestSubscriber;
use Greenlight\Plugin\HarnessProvider;
use Greenlight\Plugin\Plugin;
use Greenlight\Plugin\PluginDefinition;
use Greenlight\Plugin\RetryDecider;
use Greenlight\Plugin\TerminalResultTransformer;
use Greenlight\Plugin\TestAttemptRunner;
use Greenlight\Plugin\TestContext;
use Greenlight\Plugin\TestInstanceLeakDetector;
use Greenlight\Plugin\WorkerBootstrapContext;
use Greenlight\Plugin\WorkerBootstrapSubscriber;
use Greenlight\Plugin\WorkerRuntimeRunner;
use Greenlight\Result\FailureDetail;
use Greenlight\Result\TestResult;
use Greenlight\Result\ThrowableDetail;
use Greenlight\Test\RetryPolicy;
use Greenlight\Test\SkipTest;
use Greenlight\Test\TestDefinition;
use Greenlight\Test\TestId;

/**
 * Executes the plugin capabilities that one physical worker owns.
 *
 * @internal
 */
final readonly class WorkerPluginRuntime extends PluginRuntime
{
    /**
     * @var non-empty-list<class-string>
     */
    private const array CAPABILITIES = [
        AfterTestSubscriber::class,
        BeforeTestSubscriber::class,
        ExpectationExtension::class,
        HarnessProvider::class,
        RetryDecider::class,
        ServiceResolver::class,
        TerminalResultTransformer::class,
        TestAttemptRunner::class,
        TestInstanceLeakDetector::class,
        WorkerBootstrapSubscriber::class,
        WorkerRuntimeRunner::class,
    ];

    /**
     * @param list<PluginDefinition> $definitions
     * @param list<Plugin> $bundledPlugins
     */
    public static function fromDefinitions(array $definitions, array $bundledPlugins = []): self
    {
        return new self([
            new AttributeRetryDecider(),
            ...$bundledPlugins,
            ...self::createOwned($definitions, self::CAPABILITIES),
        ]);
    }

    /**
     * Creates a runtime from instances that Greenlight already owns.
     *
     * @internal
     *
     * @param list<Plugin> $plugins
     * @param list<Plugin> $bundledPlugins
     */
    public static function fromPlugins(array $plugins, array $bundledPlugins = []): self
    {
        return new self([new AttributeRetryDecider(), ...$bundledPlugins, ...$plugins]);
    }

    /**
     * Adds capabilities that Greenlight enables for one assignment.
     *
     * @internal
     *
     * @param list<Plugin> $plugins
     */
    public function withBundledPlugins(array $plugins): self
    {
        return new self([...$this->instances(), ...$plugins]);
    }

    /**
     * @param list<PluginDefinition> $definitions
     */
    public static function requiresInitialBootstrapBarrier(array $definitions): bool
    {
        return \array_any(
            $definitions,
            static fn(PluginDefinition $definition): bool => $definition->supports(WorkerBootstrapSubscriber::class),
        );
    }

    /**
     * @param list<ServiceDefinition> $definitions
     */
    public function prepareWorker(WorkerBootstrapContext $context, array $definitions): HarnessScopes
    {
        foreach ($this->ordered(WorkerBootstrapSubscriber::class) as $subscriber) {
            $subscriber->onWorkerBootstrap($context);
        }

        Expect::install($this->matching(ExpectationExtension::class));

        foreach ($this->ordered(HarnessProvider::class) as $provider) {
            $definitions = [...$definitions, ...$provider->services()];
        }

        $resolvers = $this->ordered(ServiceResolver::class);

        return new HarnessScopes($definitions, [
            ...\array_values(\array_filter(
                $resolvers,
                static fn(ServiceResolver $resolver): bool => !$resolver instanceof TerminalServiceResolver,
            )),
            ...\array_values(\array_filter(
                $resolvers,
                static fn(ServiceResolver $resolver): bool => $resolver instanceof TerminalServiceResolver,
            )),
        ]);
    }

    /**
     * @template T
     *
     * @param \Closure(): T $worker
     *
     * @return T
     */
    public function runWorker(\Closure $worker): mixed
    {
        foreach (\array_reverse($this->ordered(WorkerRuntimeRunner::class)) as $runner) {
            $next = $worker;
            $worker = static fn(): mixed => $runner->runWorker($next);
        }

        return $worker();
    }

    /**
     * @template T
     *
     * @param \Closure(): T $attempt
     *
     * @return T
     */
    public function runTestAttempt(\Closure $attempt): mixed
    {
        foreach (\array_reverse($this->ordered(TestAttemptRunner::class)) as $runner) {
            $next = $attempt;
            $attempt = static fn(): mixed => $runner->runTestAttempt($next);
        }

        return $attempt();
    }

    /**
     * @throws SkipTest
     * @throws PluginRuntimeError
     */
    public function beforeTest(TestContext $context): void
    {
        foreach ($this->ordered(BeforeTestSubscriber::class) as $subscriber) {
            try {
                $subscriber->beforeTest($context);
            } catch (SkipTest $skip) {
                throw $skip;
            } catch (\Throwable $failure) {
                throw PluginRuntimeError::hookFailed($subscriber::class, 'beforeTest', $failure);
            }
        }
    }

    public function afterTest(TestContext $context, TestResult $result): TestResult
    {
        foreach (\array_reverse($this->ordered(AfterTestSubscriber::class)) as $subscriber) {
            try {
                $replacement = $subscriber->afterTest($context, $result);
            } catch (\Throwable $failure) {
                $result = $this->hookFailure($subscriber::class, 'afterTest', $result, $failure);

                continue;
            }

            $result = $this->validatedReplacement($subscriber::class, 'afterTest', $result, $replacement);
        }

        return $result;
    }

    public function terminalResult(TestDefinition $definition, TestResult $result): TestResult
    {
        foreach ($this->ordered(TerminalResultTransformer::class) as $transformer) {
            try {
                $replacement = $transformer->transformTerminalResult($definition, $result);
            } catch (\Throwable $failure) {
                $result = $this->hookFailure(
                    $transformer::class,
                    'transformTerminalResult',
                    $result,
                    $failure,
                );

                continue;
            }

            $result = $this->validatedReplacement(
                $transformer::class,
                'transformTerminalResult',
                $result,
                $replacement,
            );
        }

        return $result;
    }

    /** @throws PluginRuntimeError */
    public function watchTestInstance(TestId $id, object $instance): void
    {
        foreach ($this->ordered(TestInstanceLeakDetector::class) as $detector) {
            try {
                $detector->watch($id, $instance);
            } catch (\Throwable $failure) {
                throw PluginRuntimeError::hookFailed($detector::class, 'watch', $failure);
            }
        }
    }

    /**
     * @return list<TestId>
     * @throws PluginRuntimeError
     */
    public function detectedLeaks(): array
    {
        $leaks = [];

        foreach ($this->ordered(TestInstanceLeakDetector::class) as $detector) {
            try {
                $detected = $detector->sweep();
            } catch (\Throwable $failure) {
                throw PluginRuntimeError::hookFailed($detector::class, 'sweep', $failure);
            }

            foreach ($detected as $id) {
                if (!$id instanceof TestId) {
                    throw PluginRuntimeError::invalidLeakedTest($detector::class, $id);
                }

                $leaks[(string) $id] = $id;
            }
        }

        return \array_values($leaks);
    }

    public function shouldRetry(
        RetryPolicy $policy,
        TestResult $result,
        int $attempt,
        ?\Throwable $cause,
    ): bool {
        return \array_any(
            $this->ordered(RetryDecider::class),
            static fn(RetryDecider $decider): bool => $decider->shouldRetry($policy, $result, $attempt, $cause),
        );
    }

    /** @param class-string $plugin */
    private function hookFailure(
        string $plugin,
        string $hook,
        TestResult $result,
        \Throwable $failure,
    ): TestResult {
        if ($result->outcome->isSuccessful()) {
            return $result->erroredBy(ThrowableDetail::fromThrowable(
                PluginRuntimeError::hookFailed($plugin, $hook, $failure),
            ));
        }

        return $result->withFailures([
            ...$result->failures,
            new FailureDetail(\sprintf(
                'Plugin "%s" caused an error during %s(): %s',
                $plugin,
                $hook,
                $failure->getMessage(),
            )),
        ]);
    }

    /** @param class-string $plugin */
    private function validatedReplacement(
        string $plugin,
        string $hook,
        TestResult $result,
        TestResult $replacement,
    ): TestResult {
        if (!$replacement->id->equals($result->id)) {
            return $result->erroredBy(ThrowableDetail::fromThrowable(
                PluginRuntimeError::changedTestIdentity($plugin, $result->id, $replacement->id, $hook),
            ));
        }

        if ($replacement->outcome !== $result->outcome
            && \count($replacement->transformations) <= \count($result->transformations)
        ) {
            return $result->erroredBy(ThrowableDetail::fromThrowable(
                PluginRuntimeError::changedOutcome(
                    $plugin,
                    $result->outcome,
                    $replacement->outcome,
                ),
            ));
        }

        return $replacement;
    }
}
