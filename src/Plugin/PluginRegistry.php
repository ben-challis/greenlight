<?php

declare(strict_types=1);

namespace Greenlight\Plugin;

use Greenlight\Harness\ServiceDefinition;

/**
 * The configured plugins of one run, filtered by capability.
 *
 * Internal plugins (retry policy) run before user plugins; within each group,
 * Prioritized ordering applies with a stable sort.
 *
 * @internal
 */
final readonly class PluginRegistry
{
    /**
     * @param list<object> $plugins
     */
    public function __construct(
        private array $plugins = [],
    ) {}

    /**
     * @param list<object> $userPlugins
     */
    public static function forWorker(array $userPlugins): self
    {
        return new self([new RetryPlugin(), ...$userPlugins]);
    }

    /**
     * @param list<object> $userPlugins
     */
    public static function orchestratorSide(array $userPlugins): self
    {
        return new self($userPlugins);
    }

    public static function none(): self
    {
        return new self([]);
    }

    /**
     * @return list<TestLifecycleSubscriber>
     */
    public function testSubscribers(): array
    {
        return $this->sorted($this->ofType(TestLifecycleSubscriber::class));
    }

    /**
     * @return list<RetryDecider>
     */
    public function retryDeciders(): array
    {
        return $this->sorted($this->ofType(RetryDecider::class));
    }

    /**
     * @return list<RunLifecycleSubscriber>
     */
    public function runSubscribers(): array
    {
        return $this->sorted($this->ofType(RunLifecycleSubscriber::class));
    }

    /**
     * @return list<ServiceDefinition>
     */
    public function harnessServices(): array
    {
        $definitions = [];

        foreach ($this->ofType(HarnessProvider::class) as $provider) {
            $definitions = [...$definitions, ...$provider->services()];
        }

        return $definitions;
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $type
     *
     * @return list<T>
     */
    public function ofType(string $type): array
    {
        $matching = [];

        foreach ($this->plugins as $plugin) {
            if ($plugin instanceof $type) {
                $matching[] = $plugin;
            }
        }

        return $matching;
    }

    /**
     * @template T of object
     *
     * @param list<T> $subscribers
     *
     * @return list<T>
     */
    private function sorted(array $subscribers): array
    {
        \usort(
            $subscribers,
            static fn(object $a, object $b): int =>
                ($a instanceof Prioritized ? $a->priority() : 0) <=> ($b instanceof Prioritized ? $b->priority() : 0),
        );

        return $subscribers;
    }
}
