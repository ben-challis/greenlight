<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Harness;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
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
            throw new \RuntimeException('Expected an ArrayObject.');
        }

        // Realise the lazy proxy so state from the factory result holds.
        Expect::that($resolver->consulted)->toBe(false)
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

        Expect::that($resolved)->toBeInstanceOf(\ArrayObject::class)
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

        Expect::that($scopes->resolve(\ArrayObject::class, 'test'))->toBe($answer);
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
        })->toThrow(UnresolvableService::class, matching: '/is not that type/');
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
        })->toThrow(UnresolvableService::class, matching: '/none of the 1 fallback resolver/');
    }

    #[Test]
    public function withoutResolversTheOriginalMessageStands(): void
    {
        $scopes = new HarnessScopes(new HarnessRegistry());

        Expect::that(static function () use ($scopes): void {
            $scopes->resolve(\ArrayObject::class, 'test');
        })->toThrow(UnresolvableService::class, matching: '/exact types only\.$/');
    }
}
