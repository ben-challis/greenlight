<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Test;

use Greenlight\Attribute\Test;
use Greenlight\Internal\Wire\InvalidWirePayload;
use Greenlight\Test\RetryPolicy;
use Greenlight\Tests\Support\JsonWire;

use function Greenlight\expect;

final readonly class RetryPolicyTest
{
    #[Test]
    public function survivesTheWire(): void
    {
        $policy = new RetryPolicy(3, \RuntimeException::class);
        $restored = RetryPolicy::fromWire(JsonWire::roundTrip($policy->toWire()));

        expect($restored->toWire())
            ->because('the retry policy MUST survive the wire')
            ->toBe($policy->toWire());
    }

    #[Test]
    public function rejectsInvalidTimesOnBothSides(): void
    {
        expect()->calling(static fn(): RetryPolicy => new RetryPolicy(0))
            ->because('a direct retry policy MUST require a positive count')
            ->toThrow(\InvalidArgumentException::class, message: 'Retry times must be at least 1.');

        $payload = new RetryPolicy()->toWire();
        $payload['times'] = 0;

        expect()->calling(static fn(): RetryPolicy => RetryPolicy::fromWire($payload))
            ->because('a wire retry policy MUST require a positive count')
            ->toThrow(InvalidWirePayload::class);
    }
}
