<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Plugin;

use Greenlight\Attribute\Test;
use Greenlight\Doubles\Fake;
use Greenlight\Expect\Expect;
use Greenlight\Harness\Scope;
use Greenlight\Harness\ServiceDefinition;
use Greenlight\Plugin\HarnessProvider;
use Greenlight\Plugin\PluginRegistry;
use Greenlight\Plugin\Prioritized;

final class PluginRegistryHarnessPriorityTest
{
    #[Test]
    public function harnessProvidersKeepStablePriorityOrder(): void
    {
        $late = $this->prioritizedProvider(
            $this->definition(\DateTimeImmutable::class),
            10,
        );
        $default = $this->provider($this->definition(\ArrayObject::class));
        $samePriority = $this->prioritizedProvider(
            $this->definition(\stdClass::class),
            0,
        );
        $early = $this->prioritizedProvider(
            $this->definition(\Exception::class),
            -10,
        );

        $registry = new PluginRegistry([
            $late,
            $default,
            $samePriority,
            $early,
        ]);

        Expect::that(\array_map(
            static fn(ServiceDefinition $definition): string => $definition->type,
            $registry->harnessServices(),
        ))
            ->because('harness providers MUST keep stable plugin priority order')
            ->toBe([
                \Exception::class,
                \ArrayObject::class,
                \stdClass::class,
                \DateTimeImmutable::class,
            ]);
    }

    /**
     * @param class-string<object> $type
     */
    private function definition(string $type): ServiceDefinition
    {
        return new ServiceDefinition(
            $type,
            Scope::PerRun,
            static fn(): object => new $type(),
        );
    }

    private function provider(ServiceDefinition $definition): HarnessProvider
    {
        return new readonly class ($definition) implements Fake, HarnessProvider {
            public function __construct(
                private ServiceDefinition $definition,
            ) {}

            #[\Override]
            public function services(): array
            {
                return [$this->definition];
            }
        };
    }

    private function prioritizedProvider(
        ServiceDefinition $definition,
        int $priority,
    ): HarnessProvider {
        return new readonly class ($definition, $priority) implements Fake, HarnessProvider, Prioritized {
            public function __construct(
                private ServiceDefinition $definition,
                private int $priority,
            ) {}

            #[\Override]
            public function services(): array
            {
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
