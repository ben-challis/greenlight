<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Discovery;

use Greenlight\Attribute\Test;
use Greenlight\Condition\EnvironmentVariableEquals;
use Greenlight\Condition\PhpVersionAtLeast;
use Greenlight\Core\Test\TestMetadata;
use Greenlight\Discovery\DiscoveryError;
use Greenlight\Discovery\TestDiscoverer;
use Greenlight\Expect\Expect;
use Greenlight\Tests\Fixture\DiscoveryAttributeArguments\ArgumentsMergeTest;
use Greenlight\Tests\Fixture\DiscoveryAttributes\AlwaysFalse;
use Greenlight\Tests\Fixture\DiscoveryAttributes\AlwaysTrue;
use Greenlight\Tests\Fixture\DiscoveryAttributes\MergedTest;
use Greenlight\Tests\Fixture\DiscoveryAttributes\PlainTest;

final class AttributeMergeTest
{
    /**
     * @return array<string, TestMetadata>
     */
    private function metadataByTest(): array
    {
        $dir = \dirname(__DIR__, 2) . '/Fixture/DiscoveryAttributes';
        $map = [];

        foreach (new TestDiscoverer()->discover([$dir])->entries as $entry) {
            $map[$entry->id->class . '::' . $entry->id->method] = $entry->metadata;
        }

        return $map;
    }

    #[Test]
    public function plainMethodOnClassWithAttributesInheritsEverything(): void
    {
        $metadata = $this->metadataByTest()[MergedTest::class . '::inheritsClassLevel'];

        Expect::that($metadata->groups)->toBe(['cls-a', 'cls-b']);
        Expect::that($metadata->skipReason)->toBe('class-wide skip');
        Expect::that($metadata->skipUnlessCondition)->toBe(AlwaysTrue::class);
        Expect::that($metadata->retryTimes)->toBe(2);
        Expect::that($metadata->retryOnlyOn)->toBe(null);
        Expect::that($metadata->timeoutSeconds)->toBe(30.0);
        Expect::that($metadata->isolated)->toBe(true);
    }

    #[Test]
    public function methodLevelAttributesWinAndGroupsMergeAsUnion(): void
    {
        $metadata = $this->metadataByTest()[MergedTest::class . '::overridesClassLevel'];

        Expect::that($metadata->groups)->toBe(['cls-a', 'cls-b', 'm']);
        Expect::that($metadata->skipReason)->toBe('method skip');
        Expect::that($metadata->skipUnlessCondition)->toBe(AlwaysFalse::class);
        Expect::that($metadata->retryTimes)->toBe(5);
        Expect::that($metadata->retryOnlyOn)->toBe(\RuntimeException::class);
        Expect::that($metadata->timeoutSeconds)->toBe(1.5);
        Expect::that($metadata->isolated)->toBe(true);
    }

    #[Test]
    public function bareMethodOnBareClassHasDefaults(): void
    {
        $metadata = $this->metadataByTest()[PlainTest::class . '::bare'];

        Expect::that($metadata->groups)->toBe([]);
        Expect::that($metadata->skipReason)->toBe(null);
        Expect::that($metadata->skipUnlessCondition)->toBe(null);
        Expect::that($metadata->retryTimes)->toBe(null);
        Expect::that($metadata->retryOnlyOn)->toBe(null);
        Expect::that($metadata->timeoutSeconds)->toBe(null);
        Expect::that($metadata->isolated)->toBe(false);
        Expect::that($metadata->dataSetProvider)->toBe(null);
    }

    #[Test]
    public function skipUnlessArgumentsInheritFromTheClassAndAreOverriddenTogether(): void
    {
        $dir = \dirname(__DIR__, 2) . '/Fixture/DiscoveryAttributeArguments';
        $map = [];

        foreach (new TestDiscoverer()->discover([$dir])->entries as $entry) {
            $map[$entry->id->method] = $entry->metadata;
        }

        $inherited = $map['inheritsClassCondition'];
        $overridden = $map['overridesClassCondition'];

        Expect::that($inherited->skipUnlessCondition)->toBe(EnvironmentVariableEquals::class);
        Expect::that($inherited->skipUnlessArguments)->toBe(['GREENLIGHT_MERGE_PROBE', 'on']);
        Expect::that($overridden->skipUnlessCondition)->toBe(PhpVersionAtLeast::class);
        Expect::that($overridden->skipUnlessArguments)->toBe(['8.0']);
        Expect::that($inherited->class)->toBe(ArgumentsMergeTest::class);
    }

    #[Test]
    public function nonScalarSkipUnlessArgumentsAreRejectedAtDiscovery(): void
    {
        $dir = \dirname(__DIR__, 2) . '/Fixture/DiscoveryAttributeArgumentsInvalid';

        try {
            new TestDiscoverer()->discover([$dir]);
        } catch (DiscoveryError $error) {
            Expect::that(
                \str_contains($error->getMessage(), 'NonScalarArgumentTest')
                && \str_contains($error->getMessage(), 'neverDiscovered')
                && \str_contains($error->getMessage(), 'array'),
            )->toBeTrue();

            return;
        }

        throw new \RuntimeException('Expected discovery to reject a non-scalar SkipUnless argument.');
    }

    #[Test]
    public function methodLevelAttributesApplyWithoutClassLevelCounterparts(): void
    {
        $metadata = $this->metadataByTest()[PlainTest::class . '::fullyDecorated'];

        Expect::that($metadata->groups)->toBe(['only-here']);
        Expect::that($metadata->skipReason)->toBe('not today');
        Expect::that($metadata->skipUnlessCondition)->toBe(AlwaysTrue::class);
        Expect::that($metadata->retryTimes)->toBe(3);
        Expect::that($metadata->retryOnlyOn)->toBe(\LogicException::class);
        Expect::that($metadata->timeoutSeconds)->toBe(2.5);
        Expect::that($metadata->isolated)->toBe(true);
    }
}
