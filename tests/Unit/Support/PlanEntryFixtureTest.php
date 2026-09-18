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

        Expect::value((string) $entry->id)
            ->because('the fixture default MUST create a runnable method identifier')
            ->toBe('Acme\\ExampleTest::runs');
        Expect::value($entry->definition->class)
            ->because('the fixture default MUST keep the test class')
            ->toBe('Acme\\ExampleTest');
        Expect::value($entry->definition->method)
            ->because('the fixture default MUST create the runs method')
            ->toBe('runs');
        Expect::value($entry->definition->scheduling->resources)
            ->toBe([]);
        Expect::value($entry->definition->scheduling->isolated)
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

        Expect::value((string) $entry->id)
            ->because('the fixture MUST retain the complete test identifier')
            ->toBe('Acme\\ExampleTest::checksValue[invalid value]');
        Expect::value($entry->definition->class)
            ->because('the fixture MUST keep identifier and metadata class fields equal')
            ->toBe($entry->id->class);
        Expect::value($entry->definition->method)
            ->because('the fixture MUST keep identifier and metadata method fields equal')
            ->toBe($entry->id->method);
        Expect::value($entry->definition->scheduling->resources)
            ->toBe(['database', 'cache']);
        Expect::value($entry->definition->scheduling->isolated)
            ->toBeTrue();
    }
}
