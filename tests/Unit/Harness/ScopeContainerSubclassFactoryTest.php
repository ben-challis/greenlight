<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Harness;

use Greenlight\Attribute\Test;
use Greenlight\Harness\Scope;
use Greenlight\Harness\ScopeContainer;
use Greenlight\Harness\ServiceDefinition;
use Greenlight\Tests\Fixture\Harness\Subclass\FrozenClockProbe;
use Greenlight\Tests\Fixture\Harness\Subclass\SystemClockProbe;

use function Greenlight\expect;

final class ScopeContainerSubclassFactoryTest
{
    #[Test]
    public function aFactoryThatReturnsASubclassSuppliesTheSubclassBehaviour(): void
    {
        $factoryCalls = 0;
        $container = new ScopeContainer();
        $service = $container->get(new ServiceDefinition(
            SystemClockProbe::class,
            Scope::PerTest,
            static function () use (&$factoryCalls): SystemClockProbe {
                ++$factoryCalls;

                return new FrozenClockProbe();
            },
        ));

        expect($service)->toBeInstanceOf(FrozenClockProbe::class);
        expect($service->now())
            ->because('the service MUST run the methods of the class that the factory returns')
            ->toBe('frozen time');

        $service->relabel('changed');
        $service->relabel('changed again');

        expect($service->label)
            ->because('a method that writes a property MUST change the factory result')
            ->toBe('changed again');
        expect($factoryCalls)
            ->because('the factory MUST run one time')
            ->toBe(1);
    }
}
