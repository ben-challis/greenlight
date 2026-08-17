<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Discovery;

use Greenlight\Attribute\Test;
use Greenlight\Discovery\DataSetExpander;
use Greenlight\Discovery\DiscoveryError;
use Greenlight\Discovery\ExecutionPlan;
use Greenlight\Discovery\PlanEntry;
use Greenlight\Discovery\TestDiscoverer;
use Greenlight\Expect\Expect;
use Greenlight\Tests\Fixture\Discovery\FakeMonotonicClock;
use Greenlight\Tests\Fixture\DiscoveryDataSets\InvalidKeyProvider;
use Greenlight\Tests\Fixture\DiscoveryDataSets\ProviderKeysTest;
use Greenlight\Tests\Fixture\DiscoveryProviderDuplicate\DuplicateKeysTest;

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

    /**
     * @return \Closure(): ExecutionPlan
     */
    private function discoverFixture(string $fixture, float $budgetSeconds = 5.0): \Closure
    {
        $directory = $this->fixtureDir($fixture);

        return static fn(): ExecutionPlan => new TestDiscoverer($budgetSeconds)->discover([$directory]);
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
    public function aTrailingNewlineKeyBecomesAStableHashPrefix(): void
    {
        $rows = new DataSetExpander()->rowsFor(
            new \ReflectionClass(self::class),
            __FUNCTION__,
            'trailingNewlineKey',
            5.0,
        );

        Expect::that(\array_keys($rows))
            ->because('a data-set key MUST NOT preserve a trailing control character')
            ->toBe([\substr(\hash('sha256', "line\n"), 0, 8)]);
    }

    /**
     * @return iterable<string, array<mixed>>
     */
    public static function trailingNewlineKey(): iterable
    {
        yield "line\n" => [];
    }

    #[Test]
    public function providerKeysMustBeStringsOrIntegers(): void
    {
        $expander = new DataSetExpander();

        Expect::that(static fn(): array => $expander->rowsFor(
            new \ReflectionClass(ProviderKeysTest::class),
            'withStringKeys',
            'rows',
            5.0,
            InvalidKeyProvider::class,
        ))
            ->because('provider keys must be strings or integers')
            ->toThrow(
                DiscoveryError::class,
                message: 'Data-set provider ' . InvalidKeyProvider::class . '::rows() '
                    . 'produced a key of type bool. Use string or integer keys.',
            );
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
        Expect::that($this->discoverFixture('DiscoveryProviderMissing'))
            ->because('missing provider fails naming it')
            ->toThrow(static function (DiscoveryError $error): void {
                Expect::that($error->getMessage())->toContain('doesNotExist');
                Expect::that($error->getMessage())->toContain('MissingProviderTest');
            });
    }

    #[Test]
    public function missingExternalProviderClassFailsNamingIt(): void
    {
        $expander = new DataSetExpander();

        Expect::that(static fn(): array => $expander->rowsFor(
            new \ReflectionClass(ProviderKeysTest::class),
            'withStringKeys',
            'rows',
            5.0,
            'Greenlight\Tests\Fixture\MissingProvider',
        ))
            ->because('missing external provider class fails naming it')
            ->toThrow(
                DiscoveryError::class,
                message: 'Test method ' . ProviderKeysTest::class . '::withStringKeys() '
                    . 'references missing data-set provider class "Greenlight\Tests\Fixture\MissingProvider".',
            );
    }

    #[Test]
    public function nonStaticProviderIsRejected(): void
    {
        Expect::that($this->discoverFixture('DiscoveryProviderInvalid'))
            ->because('non static provider is rejected')
            ->toThrow(static function (DiscoveryError $error): void {
                Expect::that($error->getMessage())->toContain('Declare the provider as public and static');
                Expect::that($error->getMessage())->toContain('instanceProvider');
            });
    }

    #[Test]
    public function nonPublicProviderIsRejected(): void
    {
        Expect::that($this->discoverFixture('DiscoveryProviderNonPublic'))
            ->because('a non-public data-set provider MUST fail discovery')
            ->toThrow(static function (DiscoveryError $error): void {
                Expect::that($error->getMessage())->toContain('Declare the provider as public and static');
                Expect::that($error->getMessage())->toContain('privateProvider');
            });
    }

    #[Test]
    public function nonIterableProviderIsRejected(): void
    {
        Expect::that($this->discoverFixture('DiscoveryProviderNotIterable'))
            ->because('non iterable provider is rejected')
            ->toThrow(static function (DiscoveryError $error): void {
                Expect::that($error->getMessage())->toContain('Return an iterable from the provider');
                Expect::that($error->getMessage())->toContain('string');
            });
    }

    #[Test]
    public function throwingProviderFailsDiscoveryWithTheCause(): void
    {
        Expect::that($this->discoverFixture('DiscoveryProviderThrows'))
            ->because('throwing provider fails discovery with the cause')
            ->toThrow(static function (DiscoveryError $error): void {
                Expect::that($error->getMessage())->toContain('provider exploded');
                Expect::that($error->getMessage())->toContain('boom');
                Expect::that($error->getPrevious())->toBeInstanceOf(\RuntimeException::class);
                Expect::that($error->getPrevious()?->getMessage())->toBe('provider exploded');
            });
    }

    #[Test]
    public function providerThatThrowsDuringIterationFailsDiscoveryWithTheCause(): void
    {
        Expect::that($this->discoverFixture('DiscoveryProviderIterationThrows'))
            ->because('provider that throws during iteration fails discovery with the cause')
            ->toThrow(static function (DiscoveryError $error): void {
                Expect::that($error->getMessage())->toContain('iteration exploded');
                Expect::that($error->getMessage())->toContain('rows');
                Expect::that($error->getPrevious())->toBeInstanceOf(\RuntimeException::class);
                Expect::that($error->getPrevious()?->getMessage())->toBe('iteration exploded');
            });
    }

    #[Test]
    public function slowProviderExceedsTheConfiguredBudget(): void
    {
        Expect::that($this->discoverFixture('DiscoveryProviderSlow', 0.005))
            ->because('slow provider exceeds the configured budget')
            ->toThrow(static function (DiscoveryError $error): void {
                Expect::that($error->getMessage())->toContain('time budget');
                Expect::that($error->getMessage())->toContain('dawdles');
            });
    }

    #[Test]
    public function providerThatFinishesSlowlyExceedsTheConfiguredBudget(): void
    {
        $clock = new FakeMonotonicClock(0, 0, 0, 6_000_000_000);
        $expander = new DataSetExpander($clock);

        Expect::that(static fn(): array => $expander->rowsFor(
            new \ReflectionClass(ProviderKeysTest::class),
            'withStringKeys',
            'stringKeys',
            5.0,
        ))
            ->because('provider that finishes slowly exceeds the configured budget')
            ->toThrow(
                DiscoveryError::class,
                message: 'Data-set provider ' . ProviderKeysTest::class . '::stringKeys() '
                    . 'exceeded the 5.000-second discovery time budget. Providers run during plan creation. '
                    . 'Keep them pure and fast.',
            );
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
        Expect::that($this->discoverFixture('DiscoveryProviderEmpty'))
            ->because('empty provider is rejected')
            ->toThrow(static function (DiscoveryError $error): void {
                Expect::that($error->getMessage())->toContain('produced no data sets');
            });
    }

    #[Test]
    public function duplicateKeysAreRejected(): void
    {
        Expect::that(
            fn(): ExecutionPlan => new TestDiscoverer()->discover([
                $this->fixtureDir('DiscoveryProviderDuplicate'),
            ]),
        )
            ->because('a data-set provider MUST NOT yield the same key more than once')
            ->toThrow(
                DiscoveryError::class,
                message: 'Data sets for ' . DuplicateKeysTest::class . '::needsData() contain key "same key" more than once. '
                    . 'Use each key only once for the test method.',
            );
    }
}
