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
use Greenlight\Plugin\TestAttemptRunner;
use Greenlight\Plugin\TestContext;
use Greenlight\Plugin\WorkerBootstrapContext;
use Greenlight\Plugin\WorkerBootstrapSubscriber;
use Greenlight\Plugin\WorkerRuntimeRunner;
use Greenlight\Result\FailureDetail;
use Greenlight\Result\TestResult;
use Greenlight\Result\ThrowableDetail;
use Greenlight\Test\RetryPolicy;
use Greenlight\Test\SkipTest;

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
        TestAttemptRunner::class,
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
                if ($result->outcome->isSuccessful()) {
                    $result = $result->erroredBy(ThrowableDetail::fromThrowable(
                        PluginRuntimeError::hookFailed($subscriber::class, 'afterTest', $failure),
                    ));
                } else {
                    $result = $result->withFailures([
                        ...$result->failures,
                        new FailureDetail(\sprintf(
                            'Plugin "%s" caused an error during afterTest(): %s',
                            $subscriber::class,
                            $failure->getMessage(),
                        )),
                    ]);
                }

                continue;
            }

            if (!$replacement->id->equals($result->id)) {
                $result = $result->erroredBy(ThrowableDetail::fromThrowable(
                    PluginRuntimeError::changedTestIdentity($subscriber::class, $result->id, $replacement->id),
                ));

                continue;
            }

            if ($replacement->outcome !== $result->outcome
                && \count($replacement->transformations) <= \count($result->transformations)
            ) {
                $result = $result->erroredBy(ThrowableDetail::fromThrowable(
                    PluginRuntimeError::changedOutcome(
                        $subscriber::class,
                        $result->outcome,
                        $replacement->outcome,
                    ),
                ));

                continue;
            }

            $result = $replacement;
        }

        return $result;
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
}
