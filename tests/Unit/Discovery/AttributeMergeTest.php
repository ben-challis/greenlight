<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Discovery;

use Greenlight\Attribute\Test;
use Greenlight\Condition\EnvironmentVariableEquals;
use Greenlight\Condition\PhpVersionAtLeast;
use Greenlight\Discovery\DiscoveryError;
use Greenlight\Discovery\Plan\ExecutionPlan;
use Greenlight\Discovery\TestDiscoverer;
use Greenlight\Test\TestDefinition;
use Greenlight\Tests\Fixture\DiscoveryAttributeArguments\ArgumentsMergeTest;
use Greenlight\Tests\Fixture\DiscoveryAttributes\AlwaysFalse;
use Greenlight\Tests\Fixture\DiscoveryAttributes\AlwaysTrue;
use Greenlight\Tests\Fixture\DiscoveryAttributes\MergedTest;
use Greenlight\Tests\Fixture\DiscoveryAttributes\PlainTest;
use Greenlight\Tests\Fixture\DiscoveryGroupInvalid\EmptyGroupTest;
use Greenlight\Tests\Support\FixturePath;

use function Greenlight\expect;

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

        expect($definition->groups)->because('plain method on class with attributes inherits everything')->toBe(['cls-a', 'cls-b']);
        expect($definition->skip->reason)->because('plain method on class with attributes inherits everything')->toBe('class-wide skip');
        expect($definition->skip->condition)->because('plain method on class with attributes inherits everything')->toBe(AlwaysTrue::class);
        expect($definition->retry->times)->because('plain method on class with attributes inherits everything')->toBe(2);
        expect($definition->retry->onlyOn)->because('plain method on class with attributes inherits everything')->toBe(null);
        expect($definition->execution->timeoutSeconds)->because('plain method on class with attributes inherits everything')->toBe(30.0);
        expect($definition->scheduling->isolated)->because('plain method on class with attributes inherits everything')->toBe(true);
        expect($definition->scheduling->resources)->because('plain method on class with attributes inherits everything')->toBe(['postgres', 'redis']);
    }

    #[Test]
    public function methodLevelAttributesWinAndGroupsMergeAsUnion(): void
    {
        $definition = $this->definitionByTest()[MergedTest::class . '::overridesClassLevel'];

        expect($definition->groups)->because('method level attributes win and groups merge as union')->toBe(['cls-a', 'cls-b', 'm']);
        expect($definition->skip->reason)->because('method level attributes win and groups merge as union')->toBe('method skip');
        expect($definition->skip->condition)->because('method level attributes win and groups merge as union')->toBe(AlwaysFalse::class);
        expect($definition->retry->times)->because('method level attributes win and groups merge as union')->toBe(5);
        expect($definition->retry->onlyOn)->because('method level attributes win and groups merge as union')->toBe(\RuntimeException::class);
        expect($definition->execution->timeoutSeconds)->because('method level attributes win and groups merge as union')->toBe(1.5);
        expect($definition->scheduling->isolated)->because('method level attributes win and groups merge as union')->toBe(true);
        expect($definition->scheduling->resources)->because('method level attributes win and groups merge as union')->toBe(['postgres', 'redis', 'sandbox']);
    }

    #[Test]
    public function bareMethodOnBareClassHasDefaults(): void
    {
        $definition = $this->definitionByTest()[PlainTest::class . '::bare'];

        expect($definition->groups)->because('bare method on bare class has defaults')->toBe([]);
        expect($definition->skip->reason)->because('bare method on bare class has defaults')->toBe(null);
        expect($definition->skip->condition)->because('bare method on bare class has defaults')->toBe(null);
        expect($definition->retry->times)->because('bare method on bare class has defaults')->toBe(null);
        expect($definition->retry->onlyOn)->because('bare method on bare class has defaults')->toBe(null);
        expect($definition->execution->timeoutSeconds)->because('bare method on bare class has defaults')->toBe(null);
        expect($definition->scheduling->isolated)->because('bare method on bare class has defaults')->toBe(false);
        expect($definition->dataProvider->method)->because('bare method on bare class has defaults')->toBe(null);
        expect($definition->scheduling->resources)->because('bare method on bare class has defaults')->toBe([]);
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

        expect($inherited->skip->condition)->because('skip unless arguments inherit from the class and are overridden together')->toBe(EnvironmentVariableEquals::class);
        expect($inherited->skip->arguments)->because('skip unless arguments inherit from the class and are overridden together')->toBe(['GREENLIGHT_MERGE_PROBE', 'on']);
        expect($overridden->skip->condition)->because('skip unless arguments inherit from the class and are overridden together')->toBe(PhpVersionAtLeast::class);
        expect($overridden->skip->arguments)->because('skip unless arguments inherit from the class and are overridden together')->toBe(['8.0']);
        expect($inherited->class)->because('skip unless arguments inherit from the class and are overridden together')->toBe(ArgumentsMergeTest::class);
    }

    #[Test]
    public function nonScalarSkipUnlessArgumentsAreRejectedAtDiscovery(): void
    {
        $dir = FixturePath::get('DiscoveryAttributeArgumentsInvalid');

        expect()->calling(static fn(): ExecutionPlan => new TestDiscoverer()->discover([$dir]))
            ->toThrow(static function (DiscoveryError $error): void {
                expect($error->getMessage())->toBe(
                    'Attribute on Greenlight\Tests\Fixture\DiscoveryAttributeArgumentsInvalid\NonScalarArgumentTest::neverDiscovered() is invalid: '
                    . 'Use a scalar or null for #[SkipUnless] argument 1 of condition "Greenlight\Condition\EnvironmentVariableEquals". Received array.',
                );
                expect($error->getPrevious())->toBeInstanceOf(\InvalidArgumentException::class);
            });
    }

    #[Test]
    public function invalidResourceNamesAreRejectedAtDiscoveryWithTheirLocation(): void
    {
        $dir = FixturePath::get('DiscoveryResourceInvalid');

        expect()->calling(static fn(): ExecutionPlan => new TestDiscoverer()->discover([$dir]))
            ->toThrow(static function (DiscoveryError $error): void {
                expect($error->getMessage())
                    ->toContain('InvalidResourceTest')
                    ->toContain('neverDiscovered')
                    ->toContain('Resource names');
                expect($error->getPrevious())->toBeInstanceOf(\InvalidArgumentException::class);
            });
    }

    #[Test]
    public function anEmptyGroupNameIsReportedAsAnInvalidAttribute(): void
    {
        $dir = FixturePath::get('DiscoveryGroupInvalid');

        expect()->calling(
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

        expect($definition->groups)->because('method level attributes apply without class level counterparts')->toBe(['only-here']);
        expect($definition->skip->reason)->because('method level attributes apply without class level counterparts')->toBe('not today');
        expect($definition->skip->condition)->because('method level attributes apply without class level counterparts')->toBe(AlwaysTrue::class);
        expect($definition->retry->times)->because('method level attributes apply without class level counterparts')->toBe(3);
        expect($definition->retry->onlyOn)->because('method level attributes apply without class level counterparts')->toBe(\LogicException::class);
        expect($definition->execution->timeoutSeconds)->because('method level attributes apply without class level counterparts')->toBe(2.5);
        expect($definition->scheduling->isolated)->because('method level attributes apply without class level counterparts')->toBe(true);
        expect($definition->scheduling->resources)->because('method level attributes apply without class level counterparts')->toBe(['method-only']);
    }
}
