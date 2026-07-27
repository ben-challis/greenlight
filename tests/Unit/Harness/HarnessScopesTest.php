<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Harness;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Expect\Fail;
use Greenlight\Harness\HarnessRegistry;
use Greenlight\Harness\HarnessScopes;
use Greenlight\Harness\Scope;
use Greenlight\Harness\ServiceDefinition;
use Greenlight\Harness\ServiceResolver;
use Greenlight\Harness\UnresolvableService;

final class HarnessScopesTest
{
    #[Test]
    public function registeredServicesWinOverFallbackResolvers(): void
    {
        $registered = new \ArrayObject();
        $registry = new HarnessRegistry([
            new ServiceDefinition(\ArrayObject::class, Scope::PerRun, static fn(): \ArrayObject => $registered),
        ]);
        $resolver = new class implements ServiceResolver {
            public bool $consulted = false;

            #[\Override]
            public function resolve(string $type, array $attributes): object
            {
                $this->consulted = true;

                return new \ArrayObject();
            }
        };

        $scopes = new HarnessScopes($registry, [$resolver]);
        $resolved = $scopes->resolve(\ArrayObject::class, 'test');

        if (!$resolved instanceof \ArrayObject) {
            Fail::because(\sprintf(
                'Expected HarnessScopes::resolve() to return ArrayObject, got %s.',
                \get_debug_type($resolved),
            ));
        }

        // Initialize the lazy proxy so it contains state from the factory
        // result.
        Expect::that($resolver->consulted)->because('registered services win over fallback resolvers')->toBe(false)
            ->and($resolved->getArrayCopy())->toBe($registered->getArrayCopy());
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

        Expect::that($resolved)->because('fallback resolvers receive the type and attributes')->toBeInstanceOf(\ArrayObject::class)
            ->and($resolver->type)->toBe(\ArrayObject::class)
            ->and($resolver->attributes)->toBe([$marker]);
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
        })->because('a resolver answering with the wrong type fails loudly')->toThrow(UnresolvableService::class, matching: '/is not that type/');
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
}
