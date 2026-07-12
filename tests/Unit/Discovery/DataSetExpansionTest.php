<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Discovery;

use Greenlight\Attribute\Test;
use Greenlight\Discovery\DiscoveryError;
use Greenlight\Discovery\ExecutionPlan;
use Greenlight\Discovery\PlanEntry;
use Greenlight\Discovery\TestDiscoverer;
use Greenlight\Expect\Expect;

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

        Expect::that($this->keysFor($plan, 'withStringKeys'))->toBe(['first case', 'second case']);
    }

    #[Test]
    public function integerKeysBecomeOrdinalStrings(): void
    {
        $plan = new TestDiscoverer()->discover([$this->fixtureDir('DiscoveryDataSets')]);

        Expect::that($this->keysFor($plan, 'withIntegerKeys'))->toBe(['#0', '#1', '#2']);
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

        Expect::that($this->keysFor($plan, 'withAwkwardKeys'))->toBe($expected);
    }

    #[Test]
    public function expandedIdsRenderWithTheirKeys(): void
    {
        $plan = new TestDiscoverer()->discover([$this->fixtureDir('DiscoveryDataSets')]);
        $rendered = \array_map(static fn(PlanEntry $entry): string => (string) $entry->id, $plan->entries);

        Expect::that(
            \in_array('Greenlight\Tests\Fixture\DiscoveryDataSets\ProviderKeysTest::withStringKeys[first case]', $rendered, true),
        )->toBeTrue();
    }

    #[Test]
    public function missingProviderFailsNamingIt(): void
    {
        $message = $this->discoveryErrorMessage('DiscoveryProviderMissing');

        Expect::that($message)->toContain('doesNotExist');
        Expect::that($message)->toContain('MissingProviderTest');
    }

    #[Test]
    public function nonStaticProviderIsRejected(): void
    {
        $message = $this->discoveryErrorMessage('DiscoveryProviderInvalid');

        Expect::that($message)->toContain('must be public and static');
        Expect::that($message)->toContain('instanceProvider');
    }

    #[Test]
    public function nonIterableProviderIsRejected(): void
    {
        $message = $this->discoveryErrorMessage('DiscoveryProviderNotIterable');

        Expect::that($message)->toContain('must return an iterable');
        Expect::that($message)->toContain('string');
    }

    #[Test]
    public function throwingProviderFailsDiscoveryWithTheCause(): void
    {
        $message = $this->discoveryErrorMessage('DiscoveryProviderThrows');

        Expect::that($message)->toContain('provider exploded');
        Expect::that($message)->toContain('boom');
    }

    #[Test]
    public function slowProviderExceedsTheConfiguredBudget(): void
    {
        $message = $this->discoveryErrorMessage('DiscoveryProviderSlow', 0.005);

        Expect::that($message)->toContain('time budget');
        Expect::that($message)->toContain('dawdles');
    }

    #[Test]
    public function slowProviderPassesUnderAGenerousBudget(): void
    {
        $plan = new TestDiscoverer(5.0)->discover([$this->fixtureDir('DiscoveryProviderSlow')]);

        Expect::that($plan->count())->toBe(3);
    }

    #[Test]
    public function emptyProviderIsRejected(): void
    {
        $message = $this->discoveryErrorMessage('DiscoveryProviderEmpty');

        Expect::that($message)->toContain('yielded no data sets');
    }

    #[Test]
    public function duplicateKeysAreRejected(): void
    {
        $message = $this->discoveryErrorMessage('DiscoveryProviderDuplicate');

        Expect::that($message)->toContain('more than once');
        Expect::that($message)->toContain('same key');
    }
}
