<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Support;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Tests\Support\PlanEntryFixture;

final readonly class PlanEntryFixtureTest
{
    #[Test]
    public function createsADefaultRunnableEntry(): void
    {
        $entry = PlanEntryFixture::create('Acme\\ExampleTest');

        Expect::that((string) $entry->id)
            ->because('the fixture default MUST create a runnable method identifier')
            ->toBe('Acme\\ExampleTest::runs');
        Expect::that([$entry->metadata->class, $entry->metadata->method])
            ->toBe(['Acme\\ExampleTest', 'runs']);
        Expect::that($entry->metadata->resources)
            ->toBe([]);
        Expect::that($entry->metadata->isolated)
            ->toBeFalse();
    }

    #[Test]
    public function createsConsistentEntriesWithSchedulingOptions(): void
    {
        $entry = PlanEntryFixture::create(
            'Acme\\ExampleTest',
            'checksValue',
            'invalid value',
            resources: ['database', 'cache', 'database'],
            isolated: true,
        );

        Expect::that((string) $entry->id)
            ->because('the fixture MUST retain the complete test identifier')
            ->toBe('Acme\\ExampleTest::checksValue[invalid value]');
        Expect::that([$entry->metadata->class, $entry->metadata->method])
            ->because('the fixture MUST keep identifier and metadata fields equal')
            ->toBe([$entry->id->class, $entry->id->method]);
        Expect::that($entry->metadata->resources)
            ->toBe(['database', 'cache']);
        Expect::that($entry->metadata->isolated)
            ->toBeTrue();
    }
}
