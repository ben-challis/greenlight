<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Discovery;

use Greenlight\Attribute\Test;
use Greenlight\Discovery\DiscoveryError;
use Greenlight\Discovery\ExecutionPlan;
use Greenlight\Discovery\PlanEntry;
use Greenlight\Discovery\TestDiscoverer;
use Greenlight\Tests\Support\Check;

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

        throw new \RuntimeException(\sprintf('Expected discovery of %s to fail.', $fixture));
    }

    #[Test]
    public function printableStringKeysAreUsedAsIs(): void
    {
        $plan = new TestDiscoverer()->discover([$this->fixtureDir('DiscoveryDataSets')]);

        Check::same(['first case', 'second case'], $this->keysFor($plan, 'withStringKeys'), 'printable string keys');
    }

    #[Test]
    public function integerKeysBecomeOrdinalStrings(): void
    {
        $plan = new TestDiscoverer()->discover([$this->fixtureDir('DiscoveryDataSets')]);

        Check::same(['#0', '#1', '#2'], $this->keysFor($plan, 'withIntegerKeys'), 'integer keys');
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

        Check::same($expected, $this->keysFor($plan, 'withAwkwardKeys'), 'derived keys');
    }

    #[Test]
    public function expandedIdsRenderWithTheirKeys(): void
    {
        $plan = new TestDiscoverer()->discover([$this->fixtureDir('DiscoveryDataSets')]);
        $rendered = \array_map(static fn(PlanEntry $entry): string => (string) $entry->id, $plan->entries);

        Check::true(
            \in_array('Greenlight\Tests\Fixture\DiscoveryDataSets\ProviderKeysTest::withStringKeys[first case]', $rendered, true),
            'expanded id to render with its data-set key',
        );
    }

    #[Test]
    public function missingProviderFailsNamingIt(): void
    {
        $message = $this->discoveryErrorMessage('DiscoveryProviderMissing');

        Check::true(\str_contains($message, 'doesNotExist'), 'message to name the provider: ' . $message);
        Check::true(\str_contains($message, 'MissingProviderTest'), 'message to name the class: ' . $message);
    }

    #[Test]
    public function nonStaticProviderIsRejected(): void
    {
        $message = $this->discoveryErrorMessage('DiscoveryProviderInvalid');

        Check::true(\str_contains($message, 'must be public and static'), 'message to state the rule: ' . $message);
        Check::true(\str_contains($message, 'instanceProvider'), 'message to name the provider: ' . $message);
    }

    #[Test]
    public function nonIterableProviderIsRejected(): void
    {
        $message = $this->discoveryErrorMessage('DiscoveryProviderNotIterable');

        Check::true(\str_contains($message, 'must return an iterable'), 'message to state the rule: ' . $message);
        Check::true(\str_contains($message, 'string'), 'message to name the actual type: ' . $message);
    }

    #[Test]
    public function throwingProviderFailsDiscoveryWithTheCause(): void
    {
        $message = $this->discoveryErrorMessage('DiscoveryProviderThrows');

        Check::true(\str_contains($message, 'provider exploded'), 'message to carry the cause: ' . $message);
        Check::true(\str_contains($message, 'boom'), 'message to name the provider: ' . $message);
    }

    #[Test]
    public function slowProviderExceedsTheConfiguredBudget(): void
    {
        $message = $this->discoveryErrorMessage('DiscoveryProviderSlow', 0.005);

        Check::true(\str_contains($message, 'time budget'), 'message to mention the budget: ' . $message);
        Check::true(\str_contains($message, 'dawdles'), 'message to name the provider: ' . $message);
    }

    #[Test]
    public function slowProviderPassesUnderAGenerousBudget(): void
    {
        $plan = new TestDiscoverer(5.0)->discover([$this->fixtureDir('DiscoveryProviderSlow')]);

        Check::same(3, $plan->count(), 'expanded plan size');
    }

    #[Test]
    public function emptyProviderIsRejected(): void
    {
        $message = $this->discoveryErrorMessage('DiscoveryProviderEmpty');

        Check::true(\str_contains($message, 'yielded no data sets'), 'message to state the rule: ' . $message);
    }

    #[Test]
    public function duplicateKeysAreRejected(): void
    {
        $message = $this->discoveryErrorMessage('DiscoveryProviderDuplicate');

        Check::true(\str_contains($message, 'more than once'), 'message to state the rule: ' . $message);
        Check::true(\str_contains($message, 'same key'), 'message to name the key: ' . $message);
    }
}
