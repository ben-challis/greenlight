<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Harness;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Doubles\Fake;
use Greenlight\Expect\Expect;
use Greenlight\Harness\Disposable;
use Greenlight\Harness\HarnessRegistry;
use Greenlight\Harness\HarnessScopes;
use Greenlight\Harness\Scope;
use Greenlight\Harness\ServiceDefinition;
use Greenlight\Harness\ServiceResolutionFailed;
use Greenlight\Harness\ServiceResolver;
use Greenlight\Harness\TerminalServiceResolver;
use Greenlight\Harness\UnresolvableService;

final class HarnessScopesTest
{
    #[Test]
    #[DataSet('closedScopes')]
    public function scopedServicesCannotResolveBeforeTheirScopeOpens(Scope $scope, string $message): void
    {
        $registry = new HarnessRegistry([
            new ServiceDefinition(
                \ArrayObject::class,
                $scope,
                static fn(): \ArrayObject => new \ArrayObject(),
            ),
        ]);
        $scopes = new HarnessScopes($registry);

        Expect::that(static fn(): object => $scopes->resolve(\ArrayObject::class, 'test'))
            ->because('a scoped service MUST not escape its configured lifetime')
            ->toThrow(\LogicException::class, message: $message);
    }

    #[Test]
    public function registeredServicesWinOverFallbackResolvers(): void
    {
        $registered = new \ArrayObject(['registered']);
        $registry = new HarnessRegistry([
            new ServiceDefinition(\ArrayObject::class, Scope::PerWorker, static fn(): \ArrayObject => $registered),
        ]);
        $resolver = new class implements ServiceResolver {
            public bool $consulted = false;

            #[\Override]
            public function resolve(string $type, array $attributes): object
            {
                $this->consulted = true;

                return new \ArrayObject(['fallback']);
            }
        };

        $scopes = new HarnessScopes($registry, [$resolver]);
        $resolved = $scopes->resolve(\ArrayObject::class, 'test');

        $values = $resolved->getArrayCopy();

        Expect::that($values)
            ->because('registered services MUST win over fallback resolvers')
            ->toBe(['registered']);
        Expect::that($resolver->consulted)
            ->because('a registered service MUST NOT consult a fallback resolver')
            ->toBeFalse();
    }

    #[Test]
    public function fallbackResolversReceiveTheTypeAndAttributes(): void
    {
        $resolver = new class implements ServiceResolver {
            public ?string $type = null;

            /** @var list<object> */
            public array $attributes = [];

            #[\Override]
            public function resolve(string $type, array $attributes): object
            {
                $this->type = $type;
                $this->attributes = $attributes;

                return new \ArrayObject();
            }
        };
        $marker = new \stdClass();

        $scopes = new HarnessScopes(new HarnessRegistry(), [$resolver]);
        $resolved = $scopes->resolve(\ArrayObject::class, 'test', [$marker]);

        Expect::that($resolved)->because('fallback resolvers receive the type and attributes')->toBeInstanceOf(\ArrayObject::class);
        Expect::that($resolver->type)->toBe(\ArrayObject::class);
        Expect::that($resolver->attributes)->toBe([$marker]);
    }

    #[Test]
    public function resolversAreConsultedInOrderUntilOneAnswers(): void
    {
        $passing = new class implements ServiceResolver {
            #[\Override]
            public function resolve(string $type, array $attributes): ?object
            {
                return null;
            }
        };
        $answer = new \ArrayObject(['answered']);
        $answering = new readonly class ($answer) implements ServiceResolver {
            /**
             * @param \ArrayObject<int, string> $answer
             */
            public function __construct(private \ArrayObject $answer) {}

            #[\Override]
            public function resolve(string $type, array $attributes): object
            {
                return $this->answer;
            }
        };

        $scopes = new HarnessScopes(new HarnessRegistry(), [$passing, $answering]);

        Expect::that($scopes->resolve(\ArrayObject::class, 'test'))->because('resolvers are consulted in order until one answers')->toBe($answer);
    }

    #[Test]
    public function resolverOwnedServicesAreNotDisposedByHarnessScopes(): void
    {
        $service = new class implements Disposable, Fake {
            public int $disposeCalls = 0;

            #[\Override]
            public function dispose(): void
            {
                ++$this->disposeCalls;
            }
        };
        $resolver = new readonly class ($service) implements ServiceResolver, Fake {
            public function __construct(private Disposable $service) {}

            #[\Override]
            public function resolve(string $type, array $attributes): object
            {
                return $this->service;
            }
        };
        $scopes = new HarnessScopes(new HarnessRegistry(), [$resolver]);

        $resolved = $scopes->resolve(Disposable::class, 'test');
        $failures = $scopes->closeWorker();

        Expect::that($resolved)
            ->because('the resolver retains ownership of the service lifecycle')
            ->toBe($service);
        Expect::that($service->disposeCalls)
            ->toBe(0);
        Expect::that($failures)
            ->toBe([]);
    }

    #[Test]
    public function aResolverAnsweringWithTheWrongTypeFailsLoudly(): void
    {
        $resolver = new class implements ServiceResolver {
            #[\Override]
            public function resolve(string $type, array $attributes): object
            {
                return new \stdClass();
            }
        };
        $scopes = new HarnessScopes(new HarnessRegistry(), [$resolver]);

        Expect::that(static function () use ($scopes): void {
            $scopes->resolve(\ArrayObject::class, 'test');
        })->because('a resolver that returns the wrong type causes an error')->toThrow(UnresolvableService::class, matching: '/is not that type/');
    }

    #[Test]
    public function anUnansweredTypeNamesTheConsultedResolvers(): void
    {
        $resolver = new class implements ServiceResolver {
            #[\Override]
            public function resolve(string $type, array $attributes): ?object
            {
                return null;
            }
        };
        $scopes = new HarnessScopes(new HarnessRegistry(), [$resolver]);

        Expect::that(static function () use ($scopes): void {
            $scopes->resolve(\ArrayObject::class, 'test');
        })->because('an unanswered type names the consulted resolvers')->toThrow(UnresolvableService::class, matching: '/none of the 1 fallback resolver/');
    }

    #[Test]
    public function withoutResolversTheOriginalMessageStands(): void
    {
        $scopes = new HarnessScopes(new HarnessRegistry());

        Expect::that(static function () use ($scopes): void {
            $scopes->resolve(\ArrayObject::class, 'test');
        })->because('without resolvers the original message stands')->toThrow(UnresolvableService::class, matching: '/exact types only\.$/');
    }

    #[Test]
    public function aFailedResolutionStopsTheResolverChain(): void
    {
        $failure = new class ('The resolver failed.') extends ServiceResolutionFailed {};
        $failing = new readonly class ($failure) implements ServiceResolver {
            public function __construct(private ServiceResolutionFailed $failure) {}

            #[\Override]
            public function resolve(string $type, array $attributes): ?object
            {
                throw $this->failure;
            }
        };
        $later = new class implements ServiceResolver {
            public bool $consulted = false;

            #[\Override]
            public function resolve(string $type, array $attributes): object
            {
                $this->consulted = true;

                return new \ArrayObject();
            }
        };
        $scopes = new HarnessScopes(new HarnessRegistry(), [$failing, $later]);

        $error = null;

        try {
            $scopes->resolve(\ArrayObject::class, 'test');
        } catch (ServiceResolutionFailed $caught) {
            $error = $caught;
        }

        Expect::that($error)
            ->because('a resolver failure MUST expose the public service resolution failure contract')
            ->toBeInstanceOf(ServiceResolutionFailed::class);
        Expect::that($error)->toBe($failure);
        Expect::that($later->consulted)
            ->because('Greenlight MUST NOT call a resolver after a failure')
            ->toBeFalse();
    }

    #[Test]
    public function aTerminalResolverMustBeFinal(): void
    {
        $terminal = new class implements TerminalServiceResolver {
            #[\Override]
            public function resolve(string $type, array $attributes): ?object
            {
                throw new class ('Terminal failure.') extends ServiceResolutionFailed {};
            }
        };
        $fallback = new class implements ServiceResolver {
            #[\Override]
            public function resolve(string $type, array $attributes): ?object
            {
                return null;
            }
        };

        Expect::that(static fn(): HarnessScopes => new HarnessScopes(new HarnessRegistry(), [$terminal, $fallback]))
            ->because('a terminal resolver MUST be the final resolver')
            ->toThrow(\InvalidArgumentException::class, message: 'A terminal service resolver MUST be the final resolver.');
    }

    #[Test]
    public function aTerminalResolverCannotReturnNull(): void
    {
        $terminal = new class implements TerminalServiceResolver {
            #[\Override]
            public function resolve(string $type, array $attributes): ?object
            {
                return null;
            }
        };
        $scopes = new HarnessScopes(new HarnessRegistry(), [$terminal]);

        Expect::that(static fn(): object => $scopes->resolve(\ArrayObject::class, 'test'))
            ->because('a terminal resolver MUST handle every request')
            ->toThrow(\LogicException::class, matching: '/Terminal service resolver .* returned null/');
    }

    #[Test]
    public function closingInactiveScopesIsIdempotent(): void
    {
        $scopes = new HarnessScopes(new HarnessRegistry());

        Expect::that($scopes->closeTest())
            ->because('closing an inactive test scope MUST be a safe no-op')
            ->toBe([]);
        Expect::that($scopes->closeClass())
            ->because('closing an inactive class scope MUST be a safe no-op')
            ->toBe([]);
    }

    #[Test]
    #[DataSet('serviceLifetimes')]
    public function servicesRespectTheirLifetimeAcrossScopeReopening(Scope $scope, bool $reused): void
    {
        $registry = new HarnessRegistry([
            new ServiceDefinition(
                \ArrayObject::class,
                $scope,
                static fn(): \ArrayObject => new \ArrayObject(),
            ),
        ]);
        $scopes = new HarnessScopes($registry);
        $scopes->openClass();
        $scopes->openTest();

        $first = $scopes->resolve(\ArrayObject::class, 'test');

        $first->append('first scope');
        $scopes->closeTest();
        $scopes->closeClass();

        if ($scope === Scope::PerTest || $scope === Scope::PerClass) {
            $message = $scope === Scope::PerTest
                ? 'No test scope is open.'
                : 'No class scope is open.';

            Expect::that(static fn(): object => $scopes->resolve(\ArrayObject::class, 'test'))
                ->because('closing a scope MUST make its services unavailable')
                ->toThrow(\LogicException::class, message: $message);
        } else {
            Expect::that($scopes->resolve(\ArrayObject::class, 'test'))
                ->because('closing narrower scopes MUST preserve broader services')
                ->toBe($first);
        }

        $scopes->openClass();
        $scopes->openTest();

        $second = $scopes->resolve(\ArrayObject::class, 'test');

        Expect::that($second === $first)
            ->because('a service instance MUST follow its configured scope lifetime')
            ->toBe($reused);
    }

    /**
     * @return iterable<string, array{Scope, non-empty-string}>
     */
    public static function closedScopes(): iterable
    {
        yield 'class' => [Scope::PerClass, 'No class scope is open.'];
        yield 'test' => [Scope::PerTest, 'No test scope is open.'];
    }

    /**
     * @return iterable<string, array{Scope, bool}>
     */
    public static function serviceLifetimes(): iterable
    {
        yield 'per test' => [Scope::PerTest, false];
        yield 'per class' => [Scope::PerClass, false];
        yield 'per worker' => [Scope::PerWorker, true];
    }
}
