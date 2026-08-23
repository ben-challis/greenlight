<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Test;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Internal\Wire\InvalidWirePayload;
use Greenlight\Test\ExecutionPolicy;
use Greenlight\Tests\Support\JsonWire;

final class ExecutionPolicyTest
{
    #[Test]
    public function survivesTheWire(): void
    {
        $policy = new ExecutionPolicy(5.5, capture: false, noExpectations: true);
        $restored = ExecutionPolicy::fromWire(JsonWire::roundTrip($policy->toWire()));

        Expect::that($restored->toWire())
            ->because('the execution policy MUST survive the wire')
            ->toBe($policy->toWire());
    }

    #[Test]
    #[DataSet('invalidTimeouts')]
    public function rejectsInvalidTimeoutsOnBothSides(float $seconds): void
    {
        Expect::that(static fn(): ExecutionPolicy => new ExecutionPolicy($seconds))
            ->because('a direct execution policy MUST require a positive finite timeout')
            ->toThrow(\InvalidArgumentException::class);

        $payload = new ExecutionPolicy()->toWire();
        $payload['timeoutSeconds'] = $seconds;

        Expect::that(static fn(): ExecutionPolicy => ExecutionPolicy::fromWire($payload))
            ->because('a wire execution policy MUST require a positive finite timeout')
            ->toThrow(InvalidWirePayload::class);
    }

    /** @return iterable<string, array{float}> */
    public static function invalidTimeouts(): iterable
    {
        yield 'zero' => [0.0];
        yield 'negative' => [-0.5];
        yield 'positive infinity' => [\INF];
        yield 'not a number' => [\NAN];
    }
}
