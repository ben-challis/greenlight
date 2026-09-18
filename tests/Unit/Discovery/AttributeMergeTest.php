<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Discovery;

use Greenlight\Attribute\Test;
use Greenlight\Condition\EnvironmentVariableEquals;
use Greenlight\Condition\PhpVersionAtLeast;
use Greenlight\Discovery\DiscoveryError;
use Greenlight\Discovery\Plan\ExecutionPlan;
use Greenlight\Discovery\TestDiscoverer;
use Greenlight\Expect\Expect;
use Greenlight\Test\TestDefinition;
use Greenlight\Tests\Fixture\DiscoveryAttributeArguments\ArgumentsMergeTest;
use Greenlight\Tests\Fixture\DiscoveryAttributes\AlwaysFalse;
use Greenlight\Tests\Fixture\DiscoveryAttributes\AlwaysTrue;
use Greenlight\Tests\Fixture\DiscoveryAttributes\MergedTest;
use Greenlight\Tests\Fixture\DiscoveryAttributes\PlainTest;
use Greenlight\Tests\Fixture\DiscoveryGroupInvalid\EmptyGroupTest;
use Greenlight\Tests\Support\FixturePath;

final class AttributeMergeTest
{
    /**
     * @return array<string, TestDefinition>
     */
    private function definitionByTest(): array
    {
        $dir = FixturePath::get('DiscoveryAttributes');
        $map = [];

        foreach (new TestDiscoverer()->discover([$dir])->entries as $entry) {
            $map[$entry->id->class . '::' . $entry->id->method] = $entry->definition;
        }

        return $map;
    }

    #[Test]
    public function plainMethodOnClassWithAttributesInheritsEverything(): void
    {
        $definition = $this->definitionByTest()[MergedTest::class . '::inheritsClassLevel'];

        Expect::value($definition->groups)->because('plain method on class with attributes inherits everything')->toBe(['cls-a', 'cls-b']);
        Expect::value($definition->skip->reason)->because('plain method on class with attributes inherits everything')->toBe('class-wide skip');
        Expect::value($definition->skip->condition)->because('plain method on class with attributes inherits everything')->toBe(AlwaysTrue::class);
        Expect::value($definition->retry->times)->because('plain method on class with attributes inherits everything')->toBe(2);
        Expect::value($definition->retry->onlyOn)->because('plain method on class with attributes inherits everything')->toBe(null);
        Expect::value($definition->execution->timeoutSeconds)->because('plain method on class with attributes inherits everything')->toBe(30.0);
        Expect::value($definition->scheduling->isolated)->because('plain method on class with attributes inherits everything')->toBe(true);
        Expect::value($definition->scheduling->resources)->because('plain method on class with attributes inherits everything')->toBe(['postgres', 'redis']);
    }

    #[Test]
    public function methodLevelAttributesWinAndGroupsMergeAsUnion(): void
    {
        $definition = $this->definitionByTest()[MergedTest::class . '::overridesClassLevel'];

        Expect::value($definition->groups)->because('method level attributes win and groups merge as union')->toBe(['cls-a', 'cls-b', 'm']);
        Expect::value($definition->skip->reason)->because('method level attributes win and groups merge as union')->toBe('method skip');
        Expect::value($definition->skip->condition)->because('method level attributes win and groups merge as union')->toBe(AlwaysFalse::class);
        Expect::value($definition->retry->times)->because('method level attributes win and groups merge as union')->toBe(5);
        Expect::value($definition->retry->onlyOn)->because('method level attributes win and groups merge as union')->toBe(\RuntimeException::class);
        Expect::value($definition->execution->timeoutSeconds)->because('method level attributes win and groups merge as union')->toBe(1.5);
        Expect::value($definition->scheduling->isolated)->because('method level attributes win and groups merge as union')->toBe(true);
        Expect::value($definition->scheduling->resources)->because('method level attributes win and groups merge as union')->toBe(['postgres', 'redis', 'sandbox']);
    }

    #[Test]
    public function bareMethodOnBareClassHasDefaults(): void
    {
        $definition = $this->definitionByTest()[PlainTest::class . '::bare'];

        Expect::value($definition->groups)->because('bare method on bare class has defaults')->toBe([]);
        Expect::value($definition->skip->reason)->because('bare method on bare class has defaults')->toBe(null);
        Expect::value($definition->skip->condition)->because('bare method on bare class has defaults')->toBe(null);
        Expect::value($definition->retry->times)->because('bare method on bare class has defaults')->toBe(null);
        Expect::value($definition->retry->onlyOn)->because('bare method on bare class has defaults')->toBe(null);
        Expect::value($definition->execution->timeoutSeconds)->because('bare method on bare class has defaults')->toBe(null);
        Expect::value($definition->scheduling->isolated)->because('bare method on bare class has defaults')->toBe(false);
        Expect::value($definition->dataProvider->method)->because('bare method on bare class has defaults')->toBe(null);
        Expect::value($definition->scheduling->resources)->because('bare method on bare class has defaults')->toBe([]);
    }

    #[Test]
    public function skipUnlessArgumentsInheritFromTheClassAndAreOverriddenTogether(): void
    {
        $dir = FixturePath::get('DiscoveryAttributeArguments');
        $map = [];

        foreach (new TestDiscoverer()->discover([$dir])->entries as $entry) {
            $map[$entry->id->method] = $entry->definition;
        }

        $inherited = $map['inheritsClassCondition'];
        $overridden = $map['overridesClassCondition'];

        Expect::value($inherited->skip->condition)->because('skip unless arguments inherit from the class and are overridden together')->toBe(EnvironmentVariableEquals::class);
        Expect::value($inherited->skip->arguments)->because('skip unless arguments inherit from the class and are overridden together')->toBe(['GREENLIGHT_MERGE_PROBE', 'on']);
        Expect::value($overridden->skip->condition)->because('skip unless arguments inherit from the class and are overridden together')->toBe(PhpVersionAtLeast::class);
        Expect::value($overridden->skip->arguments)->because('skip unless arguments inherit from the class and are overridden together')->toBe(['8.0']);
        Expect::value($inherited->class)->because('skip unless arguments inherit from the class and are overridden together')->toBe(ArgumentsMergeTest::class);
    }

    #[Test]
    public function nonScalarSkipUnlessArgumentsAreRejectedAtDiscovery(): void
    {
        $dir = FixturePath::get('DiscoveryAttributeArgumentsInvalid');

        Expect::calling(static fn(): ExecutionPlan => new TestDiscoverer()->discover([$dir]))
            ->toThrow(static function (DiscoveryError $error): void {
                Expect::value($error->getMessage())->toBe(
                    'Attribute on Greenlight\Tests\Fixture\DiscoveryAttributeArgumentsInvalid\NonScalarArgumentTest::neverDiscovered() is invalid: '
                    . 'Use a scalar or null for #[SkipUnless] argument 1 of condition "Greenlight\Condition\EnvironmentVariableEquals". Received array.',
                );
                Expect::value($error->getPrevious())->toBeInstanceOf(\InvalidArgumentException::class);
            });
    }

    #[Test]
    public function invalidResourceNamesAreRejectedAtDiscoveryWithTheirLocation(): void
    {
        $dir = FixturePath::get('DiscoveryResourceInvalid');

        Expect::calling(static fn(): ExecutionPlan => new TestDiscoverer()->discover([$dir]))
            ->toThrow(static function (DiscoveryError $error): void {
                Expect::value($error->getMessage())
                    ->toContain('InvalidResourceTest')
                    ->toContain('neverDiscovered')
                    ->toContain('Resource names');
                Expect::value($error->getPrevious())->toBeInstanceOf(\InvalidArgumentException::class);
            });
    }

    #[Test]
    public function anEmptyGroupNameIsReportedAsAnInvalidAttribute(): void
    {
        $dir = FixturePath::get('DiscoveryGroupInvalid');

        Expect::calling(
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
        $definition = $this->definitionByTest()[PlainTest::class . '::fullyDecorated'];

        Expect::value($definition->groups)->because('method level attributes apply without class level counterparts')->toBe(['only-here']);
        Expect::value($definition->skip->reason)->because('method level attributes apply without class level counterparts')->toBe('not today');
        Expect::value($definition->skip->condition)->because('method level attributes apply without class level counterparts')->toBe(AlwaysTrue::class);
        Expect::value($definition->retry->times)->because('method level attributes apply without class level counterparts')->toBe(3);
        Expect::value($definition->retry->onlyOn)->because('method level attributes apply without class level counterparts')->toBe(\LogicException::class);
        Expect::value($definition->execution->timeoutSeconds)->because('method level attributes apply without class level counterparts')->toBe(2.5);
        Expect::value($definition->scheduling->isolated)->because('method level attributes apply without class level counterparts')->toBe(true);
        Expect::value($definition->scheduling->resources)->because('method level attributes apply without class level counterparts')->toBe(['method-only']);
    }
}
