<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Harness;

use Greenlight\Attribute\Test;
use Greenlight\Doubles\Fake;
use Greenlight\Expect\Expect;
use Greenlight\Harness\HarnessRegistry;
use Greenlight\Harness\HarnessScopes;
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
            public function resolve(string $type, array $attributes): ?object
            {
                ++$this->calls;

                return null;
            }
        };
        $second = new class implements Fake, ServiceResolver {
            public int $calls = 0;

            #[\Override]
            public function resolve(string $type, array $attributes): ?object
            {
                ++$this->calls;

                return null;
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

        Expect::that([$first->calls, $second->calls])
            ->because('each reported fallback resolver MUST have received the request')
            ->toBe([1, 1]);
    }
}
