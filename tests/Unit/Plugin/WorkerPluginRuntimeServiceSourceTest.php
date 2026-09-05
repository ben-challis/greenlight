<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Plugin;

use Greenlight\Attribute\Test;
use Greenlight\Doubles\Fake;
use Greenlight\Execution\Plugin\WorkerPluginRuntime;
use Greenlight\Expect\Expect;
use Greenlight\Harness\HarnessScopes;
use Greenlight\Harness\Scope;
use Greenlight\Harness\Service;
use Greenlight\Harness\ServiceDefinition;
use Greenlight\Harness\ServiceResolutionFailed;
use Greenlight\Harness\ServiceSource;
use Greenlight\IntegrationFixture\IntegrationResources;
use Greenlight\Plugin\HarnessProvider;
use Greenlight\Plugin\Plugin;
use Greenlight\Plugin\WorkerBootstrapContext;
use Greenlight\Psr11\Psr11Plugin;
use Greenlight\Test\TestChannel;
use Greenlight\Tests\Support\Psr11\ArrayContainer;
use Greenlight\Tests\Support\Psr11\Greeter;
use Greenlight\Tests\Support\ServiceResolverProbe;
use Psr\Container\ContainerInterface;

final readonly class WorkerPluginRuntimeServiceSourceTest
{
    #[Test]
    public function aSelectedSourceBypassesGlobalServicesAndEarlierResolvers(): void
    {
        $selected = new Greeter();
        $earlier = new ServiceResolverProbe(new Greeter());
        $global = new Greeter();
        $scopes = $this->prepare([
            $earlier,
            $this->plugin(new ArrayContainer([Greeter::class => $selected]), 'billing'),
        ], [new ServiceDefinition(Greeter::class, Scope::PerWorker, static fn(): Greeter => $global)]);

        Expect::that($scopes->resolve(Greeter::class, 'test', [new Service(source: 'billing')]))->toBe($selected);
        Expect::that($earlier->calls)->toBe(0);
        Expect::that($scopes->resolve(Greeter::class, 'test'))->toBe($global);
    }

    #[Test]
    public function aSelectedSourceResolvesAnExplicitIdFromItsOwnContainer(): void
    {
        $billing = new Greeter();
        $legacy = new Greeter();
        $scopes = $this->prepare([
            $this->plugin(new ArrayContainer(['application.greeter' => $billing]), 'billing'),
            $this->plugin(new ArrayContainer(['application.greeter' => $legacy]), 'legacy'),
        ]);

        Expect::that($scopes->resolve(Greeter::class, 'test', [
            new Service('application.greeter', source: 'legacy'),
        ]))->toBe($legacy);
        Expect::that($scopes->resolve(Greeter::class, 'test', [
            new Service('application.greeter', source: 'billing'),
        ]))->toBe($billing);
    }

    #[Test]
    public function aSelectedSourceCannotUseAServiceFromAnotherContainer(): void
    {
        $scopes = $this->prepare([
            $this->plugin(new ArrayContainer(['application.greeter' => new Greeter()]), 'billing'),
            $this->plugin(new ArrayContainer([]), 'legacy'),
        ]);

        Expect::that(static fn(): object => $scopes->resolve(Greeter::class, 'test', [
            new Service('application.greeter', source: 'legacy'),
        ]))->toThrow(ServiceResolutionFailed::class, matching: '/no service "application.greeter"/');
    }

    #[Test]
    public function aMissingTypeInTheSelectedSourceDoesNotUseALaterResolver(): void
    {
        $later = new ServiceResolverProbe(new Greeter());
        $scopes = $this->prepare([
            $this->plugin(new ArrayContainer([]), 'billing'),
            $later,
        ]);

        Expect::that(static fn(): object => $scopes->resolve(Greeter::class, 'test', [
            new Service(source: 'billing'),
        ]))->toThrow(ServiceResolutionFailed::class);
        Expect::that($later->calls)->toBe(0);
    }

    #[Test]
    public function unqualifiedTypesKeepTheResolverOrderAcrossNamedSources(): void
    {
        $billing = new Greeter();
        $legacy = new Greeter();
        $scopes = $this->prepare([
            $this->plugin(new ArrayContainer([Greeter::class => $billing]), 'billing'),
            $this->plugin(new ArrayContainer([Greeter::class => $legacy]), 'legacy'),
        ]);

        Expect::that($scopes->resolve(Greeter::class, 'test'))->toBe($billing);
    }

    #[Test]
    public function anUnknownSourceDoesNotCallUnrelatedResolvers(): void
    {
        $earlier = new ServiceResolverProbe(new Greeter());
        $scopes = $this->prepare([
            $earlier,
            $this->plugin(new ArrayContainer([Greeter::class => new Greeter()]), 'billing'),
        ]);

        Expect::that(static fn(): object => $scopes->resolve(Greeter::class, 'test', [
            new Service(source: 'missing'),
        ]))->toThrow(ServiceResolutionFailed::class, matching: '/source "missing"/');
        Expect::that($earlier->calls)->toBe(0);
    }

    #[Test]
    public function providerSourcesKeepSameTypeDefinitionsAndCachesSeparate(): void
    {
        $billing = new \stdClass();
        $legacy = new \stdClass();
        $scopes = $this->prepare([
            $this->provider('billing', $billing),
            $this->provider('legacy', $legacy),
        ]);

        Expect::that($scopes->resolve(\stdClass::class, 'test', [new Service(source: 'billing')]))->toBe($billing);
        Expect::that($scopes->resolve(\stdClass::class, 'test', [new Service(source: 'legacy')]))->toBe($legacy);
        Expect::that($scopes->resolve(\stdClass::class, 'test', [new Service(source: 'billing')]))->toBe($billing);
    }

    #[Test]
    public function sameTypeContainersRequireASource(): void
    {
        $billing = new ArrayContainer([]);
        $legacy = new ArrayContainer([]);
        $scopes = $this->prepare([
            $this->plugin($billing, 'billing'),
            $this->plugin($legacy, 'legacy'),
        ]);
        $scopes->openTest();

        Expect::that($scopes->resolve(ContainerInterface::class, 'test', [new Service(source: 'billing')]))->toBe($billing);
        Expect::that($scopes->resolve(ContainerInterface::class, 'test', [new Service(source: 'legacy')]))->toBe($legacy);
        Expect::that(static fn(): object => $scopes->resolve(ContainerInterface::class, 'test'))
            ->toThrow(ServiceResolutionFailed::class, matching: '/source/');

        $scopes->closeTest();
    }

    #[Test]
    public function duplicatePluginSourcesFailWorkerPreparation(): void
    {
        Expect::that(fn(): HarnessScopes => $this->prepare([
            $this->plugin(new ArrayContainer([]), 'billing'),
            $this->plugin(new ArrayContainer([]), 'billing'),
        ]))->toThrow(\InvalidArgumentException::class, matching: '/source "billing"/');
    }

    #[Test]
    public function sourceNamesPreserveCaseAndAcceptZero(): void
    {
        $lower = new \stdClass();
        $upper = new \stdClass();
        $zero = new \stdClass();
        $scopes = $this->prepare([
            $this->provider('billing', $lower),
            $this->provider('Billing', $upper),
            $this->provider('0', $zero),
        ]);

        Expect::that($scopes->resolve(\stdClass::class, 'test', [new Service(source: 'billing')]))->toBe($lower);
        Expect::that($scopes->resolve(\stdClass::class, 'test', [new Service(source: 'Billing')]))->toBe($upper);
        Expect::that($scopes->resolve(\stdClass::class, 'test', [new Service(source: '0')]))->toBe($zero);
    }

    /**
     * @param list<Plugin> $plugins
     * @param list<ServiceDefinition> $definitions
     */
    private function prepare(array $plugins, array $definitions = []): HarnessScopes
    {
        return WorkerPluginRuntime::fromPlugins($plugins)->prepareWorker(
            new WorkerBootstrapContext('test-worker', new TestChannel(1), new IntegrationResources()),
            $definitions,
        );
    }

    private function plugin(ContainerInterface $container, string $source): Psr11Plugin
    {
        return new Psr11Plugin(static fn(): ContainerInterface => $container, source: $source);
    }

    /** @param non-empty-string $source */
    private function provider(string $source, \stdClass $service): HarnessProvider
    {
        return new readonly class ($source, $service) implements Fake, HarnessProvider, ServiceSource {
            /** @param non-empty-string $name */
            public function __construct(private string $name, private \stdClass $service) {}

            #[\Override]
            public function source(): string
            {
                return $this->name;
            }

            #[\Override]
            public function services(): array
            {
                return [new ServiceDefinition(\stdClass::class, Scope::PerWorker, fn(): \stdClass => $this->service)];
            }
        };
    }
}
