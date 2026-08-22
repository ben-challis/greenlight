<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Test;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Test\SkipPolicy;
use Greenlight\Tests\Support\JsonWire;
use Greenlight\Wire\InvalidWirePayload;

final class SkipPolicyTest
{
    #[Test]
    public function survivesTheWire(): void
    {
        $policy = new SkipPolicy('0', 'App\OnPosix', ['redis', 42, 1.5, true, null]);
        $restored = SkipPolicy::fromWire(JsonWire::roundTrip($policy->toWire()));

        Expect::that($restored->toWire())
            ->because('the complete skip policy MUST survive the wire')
            ->toBe($policy->toWire());
    }

    #[Test]
    public function rejectsNonScalarArgumentsOnBothSides(): void
    {
        Expect::that(static fn(): SkipPolicy => new SkipPolicy(arguments: [['nested']]))
            ->because('a direct skip policy MUST name the invalid argument type')
            ->toThrow(
                \InvalidArgumentException::class,
                message: 'Skip condition arguments must be scalars or null, got array.',
            );

        $payload = new SkipPolicy()->toWire();
        $payload['arguments'] = ['named' => 'value'];

        Expect::that(static fn(): SkipPolicy => SkipPolicy::fromWire($payload))
            ->because('wire skip arguments MUST use a list')
            ->toThrow(InvalidWirePayload::class);
    }

    #[Test]
    #[DataSet('nonFiniteArguments')]
    public function rejectsNonFiniteArgumentsOnBothSides(float $argument): void
    {
        Expect::that(static fn(): SkipPolicy => new SkipPolicy(arguments: [$argument]))
            ->because('direct skip arguments MUST contain finite floats')
            ->toThrow(\InvalidArgumentException::class);

        $payload = new SkipPolicy()->toWire();
        $payload['arguments'] = [$argument];

        Expect::that(static fn(): SkipPolicy => SkipPolicy::fromWire($payload))
            ->because('wire skip arguments MUST contain finite floats')
            ->toThrow(InvalidWirePayload::class);
    }

    /** @return iterable<string, array{float}> */
    public static function nonFiniteArguments(): iterable
    {
        yield 'positive infinity' => [\INF];
        yield 'negative infinity' => [-\INF];
        yield 'not a number' => [\NAN];
    }
}
