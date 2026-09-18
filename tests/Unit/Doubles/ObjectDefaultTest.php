<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Doubles;

use Greenlight\Attribute\Test;
use Greenlight\Doubles\Doubles;
use Greenlight\Expect\Expect;
use Greenlight\Tests\Fixture\Doubles\ObjectDefault;
use Greenlight\Tests\Fixture\Doubles\ObjectDefaults\ConstantObjectDefaults;
use Greenlight\Tests\Fixture\Doubles\ObjectDefaults\ContextDefaults;
use Greenlight\Tests\Fixture\Doubles\ObjectDefaults\ImportedDefaults;
use Greenlight\Tests\Fixture\Doubles\ObjectDefaults\TrackedDefaults;
use Greenlight\Tests\Fixture\Doubles\ObjectDefaults\TrackedValue;
use Greenlight\Tests\Fixture\Doubles\ObjectDefaults\TraitConsumer;

final readonly class ObjectDefaultTest
{
    public function __construct(private Doubles $doubles) {}

    #[Test]
    public function objectDefaultsAllowOmittedArguments(): void
    {
        $double = $this->doubles->spy(ObjectDefault::class);

        $double->run();

        Expect::value($this->doubles->callsTo($double, 'run'))->toBe([[]]);
        Expect::value(new \ReflectionMethod($double, 'run')->getParameters()[0]->getDefaultValue())
            ->toBeInstanceOf(\stdClass::class);
    }

    #[Test]
    public function proxyCreationDoesNotConstructParameterDefaults(): void
    {
        TrackedValue::$constructions = 0;

        $double = $this->doubles->spy(TrackedDefaults::class);

        Expect::value(TrackedValue::$constructions)->toBe(0);

        $double->run();

        Expect::value(TrackedValue::$constructions)->toBe(1);
        Expect::value($this->doubles->callsTo($double, 'run'))->toBe([[]]);
    }

    #[Test]
    public function eachOmittedArgumentConstructsAFreshObject(): void
    {
        TrackedValue::$constructions = 0;
        $double = $this->doubles->spy(TrackedDefaults::class);

        $double->run(marker: 'first');
        $double->run(marker: 'second');

        $calls = $this->doubles->callsTo($double, 'run');
        $first = $calls[0][0] ?? null;
        $second = $calls[1][0] ?? null;
        Expect::value($calls)->toHaveCount(2);
        Expect::value($first)->toBeInstanceOf(TrackedValue::class);
        Expect::value($second)->toBeInstanceOf(TrackedValue::class);
        Expect::value($first)->not()->toBe($second);
        Expect::value(TrackedValue::$constructions)->toBe(2);
    }

    #[Test]
    public function explicitArgumentsDoNotConstructTheDefault(): void
    {
        TrackedValue::$constructions = 0;
        $value = new TrackedValue('explicit');
        $double = $this->doubles->spy(TrackedDefaults::class);

        $double->run($value);

        Expect::value(TrackedValue::$constructions)->toBe(1);
        Expect::value($this->doubles->callsTo($double, 'run'))->toBe([[$value]]);
    }

    #[Test]
    public function objectDefaultsPreserveImportsAndNamedConstructorArguments(): void
    {
        $double = $this->doubles->spy(ImportedDefaults::class);
        $original = new \ReflectionMethod(ImportedDefaults::class, 'run')->getParameters()[0]->getDefaultValue();
        $generated = new \ReflectionMethod($double, 'run')->getParameters()[0]->getDefaultValue();

        $double->run(marker: 'named');

        Expect::value($generated)->toEqual($original);
        Expect::value($this->doubles->callsTo($double, 'run'))->toEqual([[$original, 'named']]);
    }

    #[Test]
    public function arraysPreserveNestedObjectsEnumsAndStringBytes(): void
    {
        $double = $this->doubles->spy(ImportedDefaults::class);
        $original = new \ReflectionMethod(ImportedDefaults::class, 'nested')->getParameters()[0]->getDefaultValue();
        $generated = new \ReflectionMethod($double, 'nested')->getParameters()[0]->getDefaultValue();

        $double->nested(marker: 'nested');

        Expect::value($generated)->toEqual($original);
        Expect::value($this->doubles->callsTo($double, 'nested'))->toEqual([[$original, 'nested']]);
    }

    #[Test]
    public function classDefaultsResolvePrivateSelfAndParentConstants(): void
    {
        $double = $this->doubles->spy(ContextDefaults::class);
        $original = new \ReflectionMethod(ContextDefaults::class, 'run')->getParameters()[0]->getDefaultValue();
        $generated = new \ReflectionMethod($double, 'run')->getParameters()[0]->getDefaultValue();

        $double->run(marker: 'class');

        Expect::value($generated)->toEqual($original);
        Expect::value($this->doubles->callsTo($double, 'run'))->toEqual([[$original, 'class']]);
    }

    #[Test]
    public function inheritedMethodsKeepTheirOriginalClassContext(): void
    {
        $double = $this->doubles->spy(ContextDefaults::class);
        $original = new \ReflectionMethod(ContextDefaults::class, 'inherited')->getParameters()[0]->getDefaultValue();
        $generated = new \ReflectionMethod($double, 'inherited')->getParameters()[0]->getDefaultValue();

        $double->inherited(marker: 'parent');

        Expect::value($generated)->toEqual($original);
        Expect::value($this->doubles->callsTo($double, 'inherited'))->toEqual([[$original, 'parent']]);
    }

    #[Test]
    public function traitAliasesKeepTheOriginalImportsAndMagicConstants(): void
    {
        $double = $this->doubles->spy(TraitConsumer::class);
        $original = new \ReflectionMethod(TraitConsumer::class, 'aliasDefault')->getParameters()[0]->getDefaultValue();
        $generated = new \ReflectionMethod($double, 'aliasDefault')->getParameters()[0]->getDefaultValue();

        $double->aliasDefault(marker: 'trait');

        Expect::value($generated)->toEqual($original);
        Expect::value($this->doubles->callsTo($double, 'aliasDefault'))->toEqual([[$original, 'trait']]);
    }

    #[Test]
    public function arraysRetainTheIdentityOfObjectConstants(): void
    {
        $double = $this->doubles->spy(ConstantObjectDefaults::class);
        $original = new \ReflectionMethod(ConstantObjectDefaults::class, 'nested')->getParameters()[0]->getDefaultValue();
        $generated = new \ReflectionMethod($double, 'nested')->getParameters()[0]->getDefaultValue();

        $double->nested(marker: 'shared');

        Expect::value($generated)->toBe($original);
        Expect::value($this->doubles->callsTo($double, 'nested'))->toBe([[$original, 'shared']]);
    }

    #[Test]
    public function globalConstantDefaultsRetainTheirObjectIdentity(): void
    {
        $double = $this->doubles->spy(ConstantObjectDefaults::class);
        $original = new \ReflectionMethod(ConstantObjectDefaults::class, 'globalConstant')->getParameters()[0]->getDefaultValue();
        $generated = new \ReflectionMethod($double, 'globalConstant')->getParameters()[0]->getDefaultValue();

        $double->globalConstant(marker: 'global');

        Expect::value($generated)->toBe($original);
        Expect::value($this->doubles->callsTo($double, 'globalConstant'))->toBe([[$original, 'global']]);
    }

    #[Test]
    public function publicConstantDefaultsRetainTheirObjectIdentity(): void
    {
        $double = $this->doubles->spy(ConstantObjectDefaults::class);
        $original = new \ReflectionMethod(ConstantObjectDefaults::class, 'publicConstant')->getParameters()[0]->getDefaultValue();
        $generated = new \ReflectionMethod($double, 'publicConstant')->getParameters()[0]->getDefaultValue();

        $double->publicConstant(marker: 'public');

        Expect::value($generated)->toBe($original);
        Expect::value($this->doubles->callsTo($double, 'publicConstant'))->toBe([[$original, 'public']]);
    }
}
