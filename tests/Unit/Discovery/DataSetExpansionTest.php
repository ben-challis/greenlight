<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Discovery;

use Greenlight\Attribute\Test;
use Greenlight\Discovery\DiscoveryError;
use Greenlight\Discovery\ExecutionPlan;
use Greenlight\Discovery\PlanEntry;
use Greenlight\Discovery\TestDiscoverer;
use Greenlight\Expect\Expect;
use Greenlight\Expect\Fail;

final class DataSetExpansionTest
{
    /**
     * @return non-empty-string
     */
    private function fixtureDir(string $name): string
    {
        return \dirname(__DIR__, 2) . '/Fixture/' . $name;
    }

    /**
     * @return list<string|null>
     */
    private function keysFor(ExecutionPlan $plan, string $method): array
    {
        $keys = [];

        foreach ($plan->entries as $entry) {
            if ($entry->id->method === $method) {
                $keys[] = $entry->id->dataSetKey;
            }
        }

        return $keys;
    }

    private function discoveryErrorMessage(string $fixture, float $budgetSeconds = 5.0): string
    {
        try {
            new TestDiscoverer($budgetSeconds)->discover([$this->fixtureDir($fixture)]);
        } catch (DiscoveryError $e) {
            return $e->getMessage();
        }

        Fail::because(\sprintf('Expected discovery of %s to fail.', $fixture));
    }

    #[Test]
    public function printableStringKeysAreUsedAsIs(): void
    {
        $plan = new TestDiscoverer()->discover([$this->fixtureDir('DiscoveryDataSets')]);

        Expect::that($this->keysFor($plan, 'withStringKeys'))->because('printable string keys are used as is')->toBe(['first case', 'second case']);
    }

    #[Test]
    public function integerKeysBecomeOrdinalStrings(): void
    {
        $plan = new TestDiscoverer()->discover([$this->fixtureDir('DiscoveryDataSets')]);

        Expect::that($this->keysFor($plan, 'withIntegerKeys'))->because('integer keys become ordinal strings')->toBe(['#0', '#1', '#2']);
    }

    #[Test]
    public function nonPrintableAndEmptyKeysBecomeStableHashPrefixes(): void
    {
        $plan = new TestDiscoverer()->discover([$this->fixtureDir('DiscoveryDataSets')]);

        $expected = [
            \substr(\hash('sha256', "tab\tseparated"), 0, 8),
            \substr(\hash('sha256', "\x80\x81"), 0, 8),
            \substr(\hash('sha256', ''), 0, 8),
        ];

        Expect::that($this->keysFor($plan, 'withAwkwardKeys'))->because('non printable and empty keys become stable hash prefixes')->toBe($expected);
    }

    #[Test]
    public function expandedIdsRenderWithTheirKeys(): void
    {
        $plan = new TestDiscoverer()->discover([$this->fixtureDir('DiscoveryDataSets')]);
        $rendered = \array_map(static fn(PlanEntry $entry): string => (string) $entry->id, $plan->entries);

        Expect::that($rendered)->because('expanded IDs render with their keys')->toContain(
            'Greenlight\Tests\Fixture\DiscoveryDataSets\ProviderKeysTest::withStringKeys[first case]',
        );
    }

    #[Test]
    public function missingProviderFailsNamingIt(): void
    {
        $message = $this->discoveryErrorMessage('DiscoveryProviderMissing');

        Expect::that($message)->because('missing provider fails naming it')->toContain('doesNotExist');
        Expect::that($message)->because('missing provider fails naming it')->toContain('MissingProviderTest');
    }

    #[Test]
    public function nonStaticProviderIsRejected(): void
    {
        $message = $this->discoveryErrorMessage('DiscoveryProviderInvalid');

        Expect::that($message)->because('non static provider is rejected')->toContain('Declare the provider as public and static');
        Expect::that($message)->because('non static provider is rejected')->toContain('instanceProvider');
    }

    #[Test]
    public function nonIterableProviderIsRejected(): void
    {
        $message = $this->discoveryErrorMessage('DiscoveryProviderNotIterable');

        Expect::that($message)->because('non iterable provider is rejected')->toContain('Return an iterable from the provider');
        Expect::that($message)->because('non iterable provider is rejected')->toContain('string');
    }

    #[Test]
    public function throwingProviderFailsDiscoveryWithTheCause(): void
    {
        $message = $this->discoveryErrorMessage('DiscoveryProviderThrows');

        Expect::that($message)->because('throwing provider fails discovery with the cause')->toContain('provider exploded');
        Expect::that($message)->because('throwing provider fails discovery with the cause')->toContain('boom');
    }

    #[Test]
    public function providerThatThrowsDuringIterationFailsDiscoveryWithTheCause(): void
    {
        $message = $this->discoveryErrorMessage('DiscoveryProviderIterationThrows');

        Expect::that($message)
            ->because('provider that throws during iteration fails discovery with the cause')
            ->toContain('iteration exploded')
            ->and($message)
            ->toContain('rows');
    }

    #[Test]
    public function slowProviderExceedsTheConfiguredBudget(): void
    {
        $message = $this->discoveryErrorMessage('DiscoveryProviderSlow', 0.005);

        Expect::that($message)->because('slow provider exceeds the configured budget')->toContain('time budget');
        Expect::that($message)->because('slow provider exceeds the configured budget')->toContain('dawdles');
    }

    #[Test]
    public function slowProviderPassesUnderAGenerousBudget(): void
    {
        $plan = new TestDiscoverer(5.0)->discover([$this->fixtureDir('DiscoveryProviderSlow')]);

        Expect::that($plan->count())->because('slow provider passes under a generous budget')->toBe(3);
    }

    #[Test]
    public function emptyProviderIsRejected(): void
    {
        $message = $this->discoveryErrorMessage('DiscoveryProviderEmpty');

        Expect::that($message)->because('empty provider is rejected')->toContain('produced no data sets');
    }

    #[Test]
    public function duplicateKeysAreRejected(): void
    {
        $message = $this->discoveryErrorMessage('DiscoveryProviderDuplicate');

        Expect::that($message)->because('duplicate keys are rejected')->toContain('more than once');
        Expect::that($message)->because('duplicate keys are rejected')->toContain('same key');
    }
}
