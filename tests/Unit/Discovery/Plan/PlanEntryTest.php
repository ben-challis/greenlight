<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Discovery\Plan;

use Greenlight\Attribute\Test;
use Greenlight\Discovery\Plan\PlanEntry;
use Greenlight\Expect\Expect;
use Greenlight\Test\TestDefinition;
use Greenlight\Tests\Support\JsonWire;

final readonly class PlanEntryTest
{
    #[Test]
    public function derivesIdentityFromTheDefinitionAndDataSetKey(): void
    {
        $entry = new PlanEntry(new TestDefinition('App\PaymentTest', 'chargesCard'), 'declined');

        Expect::value((string) $entry->id)
            ->because('the plan entry MUST derive the complete test ID')
            ->toBe('App\PaymentTest::chargesCard[declined]');
    }

    #[Test]
    public function wirePayloadStoresDeclarationIdentityOnce(): void
    {
        $entry = new PlanEntry(new TestDefinition('App\PaymentTest', 'chargesCard'), 'approved');
        $payload = JsonWire::roundTrip($entry->toWire());

        Expect::value($payload)
            ->because('the plan wire payload MUST not repeat declaration identity')
            ->toHaveKey('definition');
        Expect::value($payload)
            ->because('the plan wire payload MUST include data-set identity')
            ->toHaveKey('dataSetKey');
        Expect::value($payload)
            ->because('the plan wire payload MUST not include a derived test ID')
            ->not()
            ->toHaveKey('id');
        Expect::value(PlanEntry::fromWire($payload)->id->equals($entry->id))
            ->because('the derived test ID MUST survive the plan wire')
            ->toBeTrue();
    }
}
