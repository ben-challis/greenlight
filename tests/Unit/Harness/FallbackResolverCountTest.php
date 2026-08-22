<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Harness;

use Greenlight\Attribute\Test;
use Greenlight\Doubles\Fake;
use Greenlight\Expect\Expect;
use Greenlight\Harness\HarnessRegistry;
use Greenlight\Harness\HarnessScopes;
use Greenlight\Harness\ServiceResolution;
use Greenlight\Harness\ServiceResolver;
use Greenlight\Harness\UnresolvableService;

final class FallbackResolverCountTest
{
    #[Test]
    public function unansweredTypeReportsEveryConsultedResolver(): void
    {
        $first = new class implements Fake, ServiceResolver {
            public int $calls = 0;

            #[\Override]
            public function resolve(string $type, array $attributes): ServiceResolution
            {
                ++$this->calls;

                return ServiceResolution::unhandled();
            }
        };
        $second = new class implements Fake, ServiceResolver {
            public int $calls = 0;

            #[\Override]
            public function resolve(string $type, array $attributes): ServiceResolution
            {
                ++$this->calls;

                return ServiceResolution::unhandled();
            }
        };
        $scopes = new HarnessScopes(new HarnessRegistry(), [$first, $second]);

        Expect::that(static fn(): object => $scopes->resolve(
            \ArrayObject::class,
            'constructor for InvoiceTest',
        ))
            ->because('an unresolved service MUST report the complete fallback resolver chain')
            ->toThrow(
                UnresolvableService::class,
                message: 'No harness service is registered for type "ArrayObject", required by "constructor for InvoiceTest". '
                    . 'Constructor injection resolves exact types only, and none of the 2 fallback resolver(s) supplied it.',
            );

        Expect::that($first->calls)
            ->because('the first reported fallback resolver MUST receive the request')
            ->toBe(1);
        Expect::that($second->calls)
            ->because('the second reported fallback resolver MUST receive the request')
            ->toBe(1);
    }
}
