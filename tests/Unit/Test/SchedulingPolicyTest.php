<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Test;

use Greenlight\Attribute\Test;
use Greenlight\Internal\Wire\InvalidWirePayload;
use Greenlight\Test\SchedulingPolicy;
use Greenlight\Tests\Support\JsonWire;

use function Greenlight\expect;

final readonly class SchedulingPolicyTest
{
    #[Test]
    public function survivesTheWireAndRemovesDuplicateResources(): void
    {
        $policy = new SchedulingPolicy(true, ['postgres', 'redis', 'postgres'], true);
        $restored = SchedulingPolicy::fromWire(JsonWire::roundTrip($policy->toWire()));

        expect($restored->toWire())
            ->because('the scheduling policy MUST survive the wire')
            ->toBe([
                'isolated' => true,
                'resources' => ['postgres', 'redis'],
                'allowParallel' => true,
            ]);
    }

    #[Test]
    public function rejectsInvalidResourceNamesOnBothSides(): void
    {
        expect()->calling(static fn(): SchedulingPolicy => new SchedulingPolicy(resources: ['Postgres']))
            ->because('a direct scheduling policy MUST require canonical resource names')
            ->toThrow(\InvalidArgumentException::class);

        $payload = new SchedulingPolicy()->toWire();
        $payload['resources'] = ['Postgres'];

        expect()->calling(static fn(): SchedulingPolicy => SchedulingPolicy::fromWire($payload))
            ->because('a wire scheduling policy MUST require canonical resource names')
            ->toThrow(InvalidWirePayload::class);
    }
}
