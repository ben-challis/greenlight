<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Discovery;

use Greenlight\Attribute\Test;
use Greenlight\Condition\EnvironmentVariableEquals;
use Greenlight\Condition\PhpVersionAtLeast;
use Greenlight\Core\Test\TestMetadata;
use Greenlight\Discovery\DiscoveryError;
use Greenlight\Discovery\ExecutionPlan;
use Greenlight\Discovery\TestDiscoverer;
use Greenlight\Expect\Expect;
use Greenlight\Expect\Fail;
use Greenlight\Tests\Fixture\DiscoveryAttributeArguments\ArgumentsMergeTest;
use Greenlight\Tests\Fixture\DiscoveryAttributes\AlwaysFalse;
use Greenlight\Tests\Fixture\DiscoveryAttributes\AlwaysTrue;
use Greenlight\Tests\Fixture\DiscoveryAttributes\MergedTest;
use Greenlight\Tests\Fixture\DiscoveryAttributes\PlainTest;
use Greenlight\Tests\Fixture\DiscoveryGroupInvalid\EmptyGroupTest;

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

        Expect::that($metadata->groups)->because('plain method on class with attributes inherits everything')->toBe(['cls-a', 'cls-b']);
        Expect::that($metadata->skipReason)->because('plain method on class with attributes inherits everything')->toBe('class-wide skip');
        Expect::that($metadata->skipUnlessCondition)->because('plain method on class with attributes inherits everything')->toBe(AlwaysTrue::class);
        Expect::that($metadata->retryTimes)->because('plain method on class with attributes inherits everything')->toBe(2);
        Expect::that($metadata->retryOnlyOn)->because('plain method on class with attributes inherits everything')->toBe(null);
        Expect::that($metadata->timeoutSeconds)->because('plain method on class with attributes inherits everything')->toBe(30.0);
        Expect::that($metadata->isolated)->because('plain method on class with attributes inherits everything')->toBe(true);
        Expect::that($metadata->resources)->because('plain method on class with attributes inherits everything')->toBe(['postgres', 'redis']);
    }

    #[Test]
    public function methodLevelAttributesWinAndGroupsMergeAsUnion(): void
    {
        $metadata = $this->metadataByTest()[MergedTest::class . '::overridesClassLevel'];

        Expect::that($metadata->groups)->because('method level attributes win and groups merge as union')->toBe(['cls-a', 'cls-b', 'm']);
        Expect::that($metadata->skipReason)->because('method level attributes win and groups merge as union')->toBe('method skip');
        Expect::that($metadata->skipUnlessCondition)->because('method level attributes win and groups merge as union')->toBe(AlwaysFalse::class);
        Expect::that($metadata->retryTimes)->because('method level attributes win and groups merge as union')->toBe(5);
        Expect::that($metadata->retryOnlyOn)->because('method level attributes win and groups merge as union')->toBe(\RuntimeException::class);
        Expect::that($metadata->timeoutSeconds)->because('method level attributes win and groups merge as union')->toBe(1.5);
        Expect::that($metadata->isolated)->because('method level attributes win and groups merge as union')->toBe(true);
        Expect::that($metadata->resources)->because('method level attributes win and groups merge as union')->toBe(['postgres', 'redis', 'sandbox']);
    }

    #[Test]
    public function bareMethodOnBareClassHasDefaults(): void
    {
        $metadata = $this->metadataByTest()[PlainTest::class . '::bare'];

        Expect::that($metadata->groups)->because('bare method on bare class has defaults')->toBe([]);
        Expect::that($metadata->skipReason)->because('bare method on bare class has defaults')->toBe(null);
        Expect::that($metadata->skipUnlessCondition)->because('bare method on bare class has defaults')->toBe(null);
        Expect::that($metadata->retryTimes)->because('bare method on bare class has defaults')->toBe(null);
        Expect::that($metadata->retryOnlyOn)->because('bare method on bare class has defaults')->toBe(null);
        Expect::that($metadata->timeoutSeconds)->because('bare method on bare class has defaults')->toBe(null);
        Expect::that($metadata->isolated)->because('bare method on bare class has defaults')->toBe(false);
        Expect::that($metadata->dataSetProvider)->because('bare method on bare class has defaults')->toBe(null);
        Expect::that($metadata->resources)->because('bare method on bare class has defaults')->toBe([]);
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

        Expect::that($inherited->skipUnlessCondition)->because('skip unless arguments inherit from the class and are overridden together')->toBe(EnvironmentVariableEquals::class);
        Expect::that($inherited->skipUnlessArguments)->because('skip unless arguments inherit from the class and are overridden together')->toBe(['GREENLIGHT_MERGE_PROBE', 'on']);
        Expect::that($overridden->skipUnlessCondition)->because('skip unless arguments inherit from the class and are overridden together')->toBe(PhpVersionAtLeast::class);
        Expect::that($overridden->skipUnlessArguments)->because('skip unless arguments inherit from the class and are overridden together')->toBe(['8.0']);
        Expect::that($inherited->class)->because('skip unless arguments inherit from the class and are overridden together')->toBe(ArgumentsMergeTest::class);
    }

    #[Test]
    public function nonScalarSkipUnlessArgumentsAreRejectedAtDiscovery(): void
    {
        $dir = \dirname(__DIR__, 2) . '/Fixture/DiscoveryAttributeArgumentsInvalid';

        try {
            new TestDiscoverer()->discover([$dir]);
        } catch (DiscoveryError $error) {
            Expect::that($error->getMessage())->toBe(
                'Attribute on Greenlight\Tests\Fixture\DiscoveryAttributeArgumentsInvalid\NonScalarArgumentTest::neverDiscovered() is invalid: '
                . 'Use a scalar or null for #[SkipUnless] argument 1 of condition "Greenlight\Condition\EnvironmentVariableEquals". Received array.',
            );

            return;
        }

        Fail::because('Expected discovery to reject a non-scalar SkipUnless argument.');
    }

    #[Test]
    public function invalidResourceNamesAreRejectedAtDiscoveryWithTheirLocation(): void
    {
        $dir = \dirname(__DIR__, 2) . '/Fixture/DiscoveryResourceInvalid';

        try {
            new TestDiscoverer()->discover([$dir]);
        } catch (DiscoveryError $error) {
            Expect::that($error->getMessage())
                ->toContain('InvalidResourceTest')
                ->toContain('neverDiscovered')
                ->toContain('Resource names');

            return;
        }

        Fail::because('Expected discovery to reject an invalid resource name.');
    }

    #[Test]
    public function anEmptyGroupNameIsReportedAsAnInvalidAttribute(): void
    {
        $dir = \dirname(__DIR__, 2) . '/Fixture/DiscoveryGroupInvalid';

        Expect::that(
            static fn(): ExecutionPlan => new TestDiscoverer()->discover([$dir]),
        )
            ->because('an empty group name cannot enter the execution plan')
            ->toThrow(
                DiscoveryError::class,
                message: \sprintf(
                    'Attribute on %s::neverDiscovered() is invalid: Group names cannot be empty.',
                    EmptyGroupTest::class,
                ),
            );
    }

    #[Test]
    public function methodLevelAttributesApplyWithoutClassLevelCounterparts(): void
    {
        $metadata = $this->metadataByTest()[PlainTest::class . '::fullyDecorated'];

        Expect::that($metadata->groups)->because('method level attributes apply without class level counterparts')->toBe(['only-here']);
        Expect::that($metadata->skipReason)->because('method level attributes apply without class level counterparts')->toBe('not today');
        Expect::that($metadata->skipUnlessCondition)->because('method level attributes apply without class level counterparts')->toBe(AlwaysTrue::class);
        Expect::that($metadata->retryTimes)->because('method level attributes apply without class level counterparts')->toBe(3);
        Expect::that($metadata->retryOnlyOn)->because('method level attributes apply without class level counterparts')->toBe(\LogicException::class);
        Expect::that($metadata->timeoutSeconds)->because('method level attributes apply without class level counterparts')->toBe(2.5);
        Expect::that($metadata->isolated)->because('method level attributes apply without class level counterparts')->toBe(true);
        Expect::that($metadata->resources)->because('method level attributes apply without class level counterparts')->toBe(['method-only']);
    }
}
