<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Harness;

use Greenlight\Attribute\Test;
use Greenlight\Doubles\Fake;
use Greenlight\Harness\Disposable;
use Greenlight\Harness\HarnessScopes;
use Greenlight\Harness\Scope;
use Greenlight\Harness\Service;
use Greenlight\Harness\ServiceDefinition;
use Greenlight\Harness\ServiceResolver;
use Greenlight\Harness\ServiceSource;
use Greenlight\Harness\UnresolvableService;

use function Greenlight\expect;

final readonly class HarnessSourcesTest
{
    #[Test]
    public function oneNamedDefinitionCanSupplyAnUnqualifiedType(): void
    {
        $service = new \stdClass();
        $scopes = new HarnessScopes([
            new ServiceDefinition(\stdClass::class, Scope::PerWorker, static fn(): \stdClass => $service, 'billing'),
        ]);

        expect($scopes->resolve(\stdClass::class, 'test'))->toBe($service);
    }

    #[Test]
    public function duplicateTypesWithinOneSourceFailRegistration(): void
    {
        $definition = new ServiceDefinition(\stdClass::class, Scope::PerWorker, static fn(): \stdClass => new \stdClass(), 'billing');

        expect()->calling(static fn(): HarnessScopes => new HarnessScopes([$definition, $definition]))
            ->toThrow(\InvalidArgumentException::class, matching: '/source "billing" already defines type/');
    }

    #[Test]
    public function aMissingNamedDefinitionDoesNotUseAnUnnamedDefinition(): void
    {
        $scopes = new HarnessScopes([
            new ServiceDefinition(\stdClass::class, Scope::PerWorker, static fn(): \stdClass => new \stdClass()),
            new ServiceDefinition(\ArrayObject::class, Scope::PerWorker, static fn(): \ArrayObject => new \ArrayObject(), 'billing'),
        ]);

        expect()->calling(static fn(): object => $scopes->resolve(\stdClass::class, 'test', [new Service(source: 'billing')]))
            ->toThrow(UnresolvableService::class, matching: '/source "billing" cannot supply service "stdClass"/');
    }

    #[Test]
    public function aSelectedResolverCannotDeclineToAnotherSource(): void
    {
        $scopes = new HarnessScopes([], [$this->resolver(null)]);

        expect()->calling(static fn(): object => $scopes->resolve(\stdClass::class, 'test', [new Service(source: 'billing')]))
            ->toThrow(UnresolvableService::class, matching: '/source "billing" cannot supply/');
    }

    #[Test]
    public function anExplicitIdUsesTheSelectedResolverBeforeItsTypedDefinition(): void
    {
        $resolved = new \stdClass();
        $scopes = new HarnessScopes([
            new ServiceDefinition(\stdClass::class, Scope::PerWorker, static fn(): \stdClass => new \stdClass(), 'billing'),
        ], [$this->resolver($resolved)]);

        expect($scopes->resolve(\stdClass::class, 'test', [new Service('custom', 'billing')]))->toBe($resolved);
    }

    #[Test]
    public function aSelectedResolverMustSupplyTheDeclaredType(): void
    {
        $scopes = new HarnessScopes([], [$this->resolver(new \ArrayObject())]);

        expect()->calling(static fn(): object => $scopes->resolve(\stdClass::class, 'test', [new Service(source: 'billing')]))
            ->toThrow(UnresolvableService::class, matching: '/which is not that type/');
    }

    #[Test]
    public function duplicateResolverSourceNamesFailRegistration(): void
    {
        expect()->calling(fn(): HarnessScopes => new HarnessScopes([], [$this->resolver(null), $this->resolver(null)]))
            ->toThrow(\InvalidArgumentException::class, message: 'Service source "billing" is already registered.');
    }

    #[Test]
    public function namedPerClassServicesCannotEscapeParallelClassRules(): void
    {
        $scopes = new HarnessScopes([
            new ServiceDefinition(\stdClass::class, Scope::PerClass, static fn(): \stdClass => new \stdClass(), 'billing'),
        ]);
        $scopes->openClass(allowPerClassServices: false);

        expect()->calling(static fn(): object => $scopes->resolve(\stdClass::class, 'test', [new Service(source: 'billing')]))
            ->toThrow(UnresolvableService::class, matching: '/AllowParallel/');
    }

    #[Test]
    public function namedScopesDisposeInGlobalCreationOrderAndResetTheirCaches(): void
    {
        /** @var \ArrayObject<int, string> $calls */
        $calls = new \ArrayObject();
        $scopes = new HarnessScopes([
            new ServiceDefinition(Disposable::class, Scope::PerTest, fn(): Disposable => $this->disposable('global', $calls)),
            new ServiceDefinition(Disposable::class, Scope::PerTest, fn(): Disposable => $this->disposable('billing', $calls), 'billing'),
            new ServiceDefinition(Disposable::class, Scope::PerTest, fn(): Disposable => $this->disposable('legacy', $calls), 'legacy'),
        ]);
        $scopes->openTest();
        $first = $scopes->resolve(Disposable::class, 'test', [new Service(source: 'billing')]);
        $scopes->resolve(Disposable::class, 'test');
        $scopes->resolve(Disposable::class, 'test', [new Service(source: 'legacy')]);

        expect($scopes->closeTest())->toBe([]);
        expect($calls->getArrayCopy())->toBe(['legacy', 'global', 'billing']);

        $scopes->openTest();
        $second = $scopes->resolve(Disposable::class, 'test', [new Service(source: 'billing')]);

        expect($second)->not()->toBe($first);
        expect($scopes->closeTest())->toBe([]);
        expect($calls->getArrayCopy())->toBe(['legacy', 'global', 'billing', 'billing']);
    }

    private function resolver(?object $service): ServiceResolver
    {
        return new readonly class ($service) implements Fake, ServiceResolver, ServiceSource {
            public function __construct(private ?object $service) {}

            #[\Override]
            public function source(): string
            {
                return 'billing';
            }

            #[\Override]
            public function resolve(string $type, array $attributes): ?object
            {
                return $this->service;
            }
        };
    }

    /** @param \ArrayObject<int, string> $calls */
    private function disposable(string $name, \ArrayObject $calls): Disposable
    {
        return new readonly class ($name, $calls) implements Fake, Disposable {
            /** @param \ArrayObject<int, string> $calls */
            public function __construct(private string $name, private \ArrayObject $calls) {}

            #[\Override]
            public function dispose(): void
            {
                $this->calls->append($this->name);
            }
        };
    }
}
