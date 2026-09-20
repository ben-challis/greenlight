<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Discovery\Plan;

use Greenlight\Attribute\Test;
use Greenlight\Discovery\Plan\PlanEntry;
use Greenlight\Test\TestDefinition;
use Greenlight\Tests\Support\JsonWire;

use function Greenlight\expect;

final readonly class PlanEntryTest
{
    #[Test]
    public function derivesIdentityFromTheDefinitionAndDataSetKey(): void
    {
        $entry = new PlanEntry(new TestDefinition('App\PaymentTest', 'chargesCard'), 'declined');

        expect((string) $entry->id)
            ->because('the plan entry MUST derive the complete test ID')
            ->toBe('App\PaymentTest::chargesCard[declined]');
    }

    #[Test]
    public function wirePayloadStoresDeclarationIdentityOnce(): void
    {
        $entry = new PlanEntry(new TestDefinition('App\PaymentTest', 'chargesCard'), 'approved');
        $payload = JsonWire::roundTrip($entry->toWire());

        expect($payload)
            ->because('the plan wire payload MUST not repeat declaration identity')
            ->toHaveKey('definition')
            ->because('the plan wire payload MUST include data-set identity')
            ->toHaveKey('dataSetKey')
            ->because('the plan wire payload MUST not include a derived test ID')
            ->not()->toHaveKey('id');
        expect(PlanEntry::fromWire($payload)->id->equals($entry->id))
            ->because('the derived test ID MUST survive the plan wire')
            ->toBeTrue();
    }
}
