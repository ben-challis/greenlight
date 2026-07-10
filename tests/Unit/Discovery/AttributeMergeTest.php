<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Discovery;

use Greenlight\Attribute\Test;
use Greenlight\Condition\EnvironmentVariableEquals;
use Greenlight\Condition\PhpVersionAtLeast;
use Greenlight\Core\Test\TestMetadata;
use Greenlight\Discovery\DiscoveryError;
use Greenlight\Discovery\TestDiscoverer;
use Greenlight\Tests\Fixture\DiscoveryAttributeArguments\ArgumentsMergeTest;
use Greenlight\Tests\Fixture\DiscoveryAttributes\AlwaysFalse;
use Greenlight\Tests\Fixture\DiscoveryAttributes\AlwaysTrue;
use Greenlight\Tests\Fixture\DiscoveryAttributes\MergedTest;
use Greenlight\Tests\Fixture\DiscoveryAttributes\PlainTest;
use Greenlight\Tests\Support\Check;

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

        Check::same(['cls-a', 'cls-b'], $metadata->groups, 'inherited groups');
        Check::same('class-wide skip', $metadata->skipReason, 'inherited skip reason');
        Check::same(AlwaysTrue::class, $metadata->skipUnlessCondition, 'inherited skip-unless');
        Check::same(2, $metadata->retryTimes, 'inherited retry times');
        Check::same(null, $metadata->retryOnlyOn, 'inherited retry only-on');
        Check::same(30.0, $metadata->timeoutSeconds, 'inherited timeout');
        Check::same(true, $metadata->isolated, 'inherited isolation');
    }

    #[Test]
    public function methodLevelAttributesWinAndGroupsMergeAsUnion(): void
    {
        $metadata = $this->metadataByTest()[MergedTest::class . '::overridesClassLevel'];

        Check::same(['cls-a', 'cls-b', 'm'], $metadata->groups, 'union of class and method groups');
        Check::same('method skip', $metadata->skipReason, 'method skip wins');
        Check::same(AlwaysFalse::class, $metadata->skipUnlessCondition, 'method skip-unless wins');
        Check::same(5, $metadata->retryTimes, 'method retry times win');
        Check::same(\RuntimeException::class, $metadata->retryOnlyOn, 'method retry only-on wins');
        Check::same(1.5, $metadata->timeoutSeconds, 'method timeout wins');
        Check::same(true, $metadata->isolated, 'isolation still applies');
    }

    #[Test]
    public function bareMethodOnBareClassHasDefaults(): void
    {
        $metadata = $this->metadataByTest()[PlainTest::class . '::bare'];

        Check::same([], $metadata->groups, 'no groups');
        Check::same(null, $metadata->skipReason, 'no skip');
        Check::same(null, $metadata->skipUnlessCondition, 'no skip-unless');
        Check::same(null, $metadata->retryTimes, 'no retry');
        Check::same(null, $metadata->retryOnlyOn, 'no retry only-on');
        Check::same(null, $metadata->timeoutSeconds, 'no timeout');
        Check::same(false, $metadata->isolated, 'not isolated');
        Check::same(null, $metadata->dataSetProvider, 'no provider');
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

        Check::same(EnvironmentVariableEquals::class, $inherited->skipUnlessCondition, 'inherited condition');
        Check::same(['GREENLIGHT_MERGE_PROBE', 'on'], $inherited->skipUnlessArguments, 'inherited arguments');
        Check::same(PhpVersionAtLeast::class, $overridden->skipUnlessCondition, 'overriding condition');
        Check::same(['8.0'], $overridden->skipUnlessArguments, 'overriding arguments replace inherited ones');
        Check::same(ArgumentsMergeTest::class, $inherited->class, 'fixture class');
    }

    #[Test]
    public function nonScalarSkipUnlessArgumentsAreRejectedAtDiscovery(): void
    {
        $dir = \dirname(__DIR__, 2) . '/Fixture/DiscoveryAttributeArgumentsInvalid';

        try {
            new TestDiscoverer()->discover([$dir]);
        } catch (DiscoveryError $error) {
            Check::true(
                \str_contains($error->getMessage(), 'NonScalarArgumentTest')
                && \str_contains($error->getMessage(), 'neverDiscovered')
                && \str_contains($error->getMessage(), 'array'),
                'error names the class, method and offending argument type: ' . $error->getMessage(),
            );

            return;
        }

        throw new \RuntimeException('Expected discovery to reject a non-scalar SkipUnless argument.');
    }

    #[Test]
    public function methodLevelAttributesApplyWithoutClassLevelCounterparts(): void
    {
        $metadata = $this->metadataByTest()[PlainTest::class . '::fullyDecorated'];

        Check::same(['only-here'], $metadata->groups, 'method-only group');
        Check::same('not today', $metadata->skipReason, 'method-only skip');
        Check::same(AlwaysTrue::class, $metadata->skipUnlessCondition, 'method-only skip-unless');
        Check::same(3, $metadata->retryTimes, 'method-only retry times');
        Check::same(\LogicException::class, $metadata->retryOnlyOn, 'method-only retry only-on');
        Check::same(2.5, $metadata->timeoutSeconds, 'method-only timeout');
        Check::same(true, $metadata->isolated, 'method-only isolation');
    }
}
