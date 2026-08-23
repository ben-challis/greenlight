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
        Expect::that($entry->definition->class)
            ->because('the fixture default MUST keep the test class')
            ->toBe('Acme\\ExampleTest');
        Expect::that($entry->definition->method)
            ->because('the fixture default MUST create the runs method')
            ->toBe('runs');
        Expect::that($entry->definition->scheduling->resources)
            ->toBe([]);
        Expect::that($entry->definition->scheduling->isolated)
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
        Expect::that($entry->definition->class)
            ->because('the fixture MUST keep identifier and metadata class fields equal')
            ->toBe($entry->id->class);
        Expect::that($entry->definition->method)
            ->because('the fixture MUST keep identifier and metadata method fields equal')
            ->toBe($entry->id->method);
        Expect::that($entry->definition->scheduling->resources)
            ->toBe(['database', 'cache']);
        Expect::that($entry->definition->scheduling->isolated)
            ->toBeTrue();
    }
}
