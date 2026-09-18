<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Support;

use Greenlight\Attribute\Test;
use Greenlight\Tests\Support\PlanEntryFixture;

use function Greenlight\expect;

final readonly class PlanEntryFixtureTest
{
    #[Test]
    public function createsADefaultRunnableEntry(): void
    {
        $entry = PlanEntryFixture::create('Acme\\ExampleTest');

        expect((string) $entry->id)
            ->because('the fixture default MUST create a runnable method identifier')
            ->toBe('Acme\\ExampleTest::runs');
        expect($entry->definition->class)
            ->because('the fixture default MUST keep the test class')
            ->toBe('Acme\\ExampleTest');
        expect($entry->definition->method)
            ->because('the fixture default MUST create the runs method')
            ->toBe('runs');
        expect($entry->definition->scheduling->resources)
            ->toBe([]);
        expect($entry->definition->scheduling->isolated)
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

        expect((string) $entry->id)
            ->because('the fixture MUST retain the complete test identifier')
            ->toBe('Acme\\ExampleTest::checksValue[invalid value]');
        expect($entry->definition->class)
            ->because('the fixture MUST keep identifier and metadata class fields equal')
            ->toBe($entry->id->class);
        expect($entry->definition->method)
            ->because('the fixture MUST keep identifier and metadata method fields equal')
            ->toBe($entry->id->method);
        expect($entry->definition->scheduling->resources)
            ->toBe(['database', 'cache']);
        expect($entry->definition->scheduling->isolated)
            ->toBeTrue();
    }
}
