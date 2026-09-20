<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Doubles;

use Greenlight\Attribute\Test;
use Greenlight\Doubles\Doubles;
use Greenlight\Doubles\InvalidDoubleUsage;
use Greenlight\Expect\Fail;
use Greenlight\Tests\Fixture\Doubles\ProxyCacheContract;

use function Greenlight\expect;

final class ProxyClassCacheTest
{
    #[Test]
    public function aliasesAndCaseVariantsReuseTheClassAcrossFactories(): void
    {
        $alias = __NAMESPACE__ . '\\ProxyCacheAlias';
        \class_alias(ProxyCacheContract::class, $alias);
        $firstFactory = new Doubles();
        $first = $firstFactory->stub(ProxyCacheContract::class);
        $firstFactory->dispose();

        foreach ([ProxyCacheContract::class, \strtolower(ProxyCacheContract::class), '\\' . ProxyCacheContract::class, $alias, \strtoupper($alias)] as $type) {
            if (!\interface_exists($type)) {
                Fail::because('The contract name must resolve before double creation.');
            }

            $factory = new Doubles();
            $double = $factory->stub($type);

            expect($double)->toBeInstanceOf(ProxyCacheContract::class);
            expect($double::class)->toBe($first::class);
            $factory->dispose();
        }
    }

    #[Test]
    public function aLoadedProxyDoesNotMakeAnInvalidNameValid(): void
    {
        $doubles = new Doubles();
        $doubles->stub(ProxyCacheContract::class);
        $stub = new \ReflectionMethod(Doubles::class, 'stub');

        expect()->calling(static fn(): mixed => $stub->invoke($doubles, '\\\\' . ProxyCacheContract::class))
            ->because('extra namespace separators must remain invalid after the valid type is cached')
            ->toThrow(InvalidDoubleUsage::class);
        $doubles->dispose();
    }

    #[Test]
    public function aFailedLookupCanSucceedAfterTheTypeIsDefined(): void
    {
        $type = __NAMESPACE__ . '\\ProxyCacheLateAlias';
        $doubles = new Doubles();
        $stub = new \ReflectionMethod(Doubles::class, 'stub');

        expect()->calling(static fn(): mixed => $stub->invoke($doubles, $type))
            ->toThrow(InvalidDoubleUsage::class);
        \class_alias(ProxyCacheContract::class, $type);

        expect($stub->invoke($doubles, $type))->toBeInstanceOf(ProxyCacheContract::class);
        $doubles->dispose();
    }
}
