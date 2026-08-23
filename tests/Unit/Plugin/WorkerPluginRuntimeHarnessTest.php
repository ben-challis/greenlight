<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Plugin;

use Greenlight\Attribute\Test;
use Greenlight\Doubles\Fake;
use Greenlight\Execution\Plugin\WorkerPluginRuntime;
use Greenlight\Expect\Expect;
use Greenlight\Harness\Scope;
use Greenlight\Harness\ServiceDefinition;
use Greenlight\Harness\ServiceResolutionFailed;
use Greenlight\Harness\ServiceResolver;
use Greenlight\Harness\TerminalServiceResolver;
use Greenlight\IntegrationFixture\IntegrationResources;
use Greenlight\Plugin\HarnessProvider;
use Greenlight\Plugin\Plugin;
use Greenlight\Plugin\Prioritized;
use Greenlight\Plugin\WorkerBootstrapContext;
use Greenlight\Test\TestChannel;

final class WorkerPluginRuntimeHarnessTest
{
    #[Test]
    public function harnessProvidersKeepStablePriorityOrder(): void
    {
        /** @var \ArrayObject<int, string> $calls */
        $calls = new \ArrayObject();
        $late = $this->prioritizedProvider(
            $calls,
            'late',
            $this->definition(\DateTimeImmutable::class),
            10,
        );
        $default = $this->provider($calls, 'default', $this->definition(\ArrayObject::class));
        $samePriority = $this->prioritizedProvider(
            $calls,
            'same-priority',
            $this->definition(\stdClass::class),
            0,
        );
        $early = $this->prioritizedProvider(
            $calls,
            'early',
            $this->definition(\Exception::class),
            -10,
        );

        $runtime = WorkerPluginRuntime::fromPlugins([
            $late,
            $default,
            $samePriority,
            $early,
        ]);
        $runtime->prepareWorker(
            new WorkerBootstrapContext('test-worker', new TestChannel(1), new IntegrationResources()),
            [],
        );

        Expect::that($calls->getArrayCopy())
            ->because('harness providers MUST keep stable plugin priority order')
            ->toBe([
                'early',
                'default',
                'same-priority',
                'late',
            ]);
    }

    #[Test]
    public function terminalServiceResolverAlwaysFollowsFallbackResolvers(): void
    {
        $answer = new \stdClass();
        $terminal = new class implements Fake, Plugin, Prioritized, TerminalServiceResolver {
            #[\Override]
            public function priority(): int
            {
                return -100;
            }

            #[\Override]
            public function resolve(string $type, array $attributes): ?object
            {
                throw new class ('Terminal failure.') extends ServiceResolutionFailed {};
            }
        };
        $fallback = new readonly class ($answer) implements Fake, Plugin, Prioritized, ServiceResolver {
            public function __construct(private object $answer) {}

            #[\Override]
            public function priority(): int
            {
                return 100;
            }

            #[\Override]
            public function resolve(string $type, array $attributes): object
            {
                return $this->answer;
            }
        };
        $runtime = WorkerPluginRuntime::fromPlugins([$terminal, $fallback]);
        $scopes = $runtime->prepareWorker(
            new WorkerBootstrapContext('test-worker', new TestChannel(1), new IntegrationResources()),
            [],
        );

        Expect::that($scopes->resolve(\stdClass::class, 'test'))
            ->because('a terminal resolver MUST follow all fallback-capable resolvers')
            ->toBe($answer);
    }

    /**
     * @param class-string<object> $type
     */
    private function definition(string $type): ServiceDefinition
    {
        return new ServiceDefinition(
            $type,
            Scope::PerWorker,
            static fn(): object => new $type(),
        );
    }

    /** @param \ArrayObject<int, string> $calls */
    private function provider(\ArrayObject $calls, string $name, ServiceDefinition $definition): HarnessProvider
    {
        return new readonly class ($calls, $name, $definition) implements Fake, HarnessProvider {
            /** @param \ArrayObject<int, string> $calls */
            public function __construct(
                private \ArrayObject $calls,
                private string $name,
                private ServiceDefinition $definition,
            ) {}

            #[\Override]
            public function services(): array
            {
                $this->calls->append($this->name);

                return [$this->definition];
            }
        };
    }

    /** @param \ArrayObject<int, string> $calls */
    private function prioritizedProvider(
        \ArrayObject $calls,
        string $name,
        ServiceDefinition $definition,
        int $priority,
    ): HarnessProvider {
        return new readonly class ($calls, $name, $definition, $priority) implements Fake, HarnessProvider, Prioritized {
            /** @param \ArrayObject<int, string> $calls */
            public function __construct(
                private \ArrayObject $calls,
                private string $name,
                private ServiceDefinition $definition,
                private int $priority,
            ) {}

            #[\Override]
            public function services(): array
            {
                $this->calls->append($this->name);

                return [$this->definition];
            }

            #[\Override]
            public function priority(): int
            {
                return $this->priority;
            }
        };
    }
}
