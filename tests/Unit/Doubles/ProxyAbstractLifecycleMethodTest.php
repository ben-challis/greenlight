<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Doubles;

use Greenlight\Attribute\Test;
use Greenlight\Doubles\Doubles;
use Greenlight\Doubles\MockPlan;
use Greenlight\Tests\Fixture\Doubles\AbstractConstructorService;
use Greenlight\Tests\Fixture\Doubles\ConstructorContract;
use Greenlight\Tests\Fixture\Doubles\DestructorContract;

use function Greenlight\expect;

final readonly class ProxyAbstractLifecycleMethodTest
{
    public function __construct(private Doubles $doubles) {}

    #[Test]
    public function interfaceConstructorsKeepTheirDeclaredSignature(): void
    {
        $double = $this->doubles->mock(ConstructorContract::class, static function (MockPlan $plan): void {
            $plan->expects('name')->once()->andReturns('double');
        });
        $constructor = new \ReflectionMethod($double, '__construct');

        expect($double->name())
            ->because('an interface constructor MUST NOT prevent the proxy declaration')
            ->toBe('double');
        expect(\array_map(static fn(\ReflectionParameter $parameter): string => $parameter->name, $constructor->getParameters()))
            ->toBe(['name', 'size']);
    }

    #[Test]
    public function abstractConstructorsGetAnEmptyImplementation(): void
    {
        $double = $this->doubles->mock(AbstractConstructorService::class, static function (MockPlan $plan): void {
            $plan->expects('name')->once()->andReturns('double');
        });

        expect($double->name())
            ->because('an abstract constructor MUST NOT prevent the proxy declaration')
            ->toBe('double');
        expect(new \ReflectionMethod($double, '__construct')->isAbstract())
            ->toBeFalse();
    }

    #[Test]
    public function interfaceDestructorsGetAnEmptyImplementation(): void
    {
        $double = $this->doubles->spy(DestructorContract::class);
        $double->close();

        expect(new \ReflectionMethod($double, '__destruct')->isAbstract())
            ->because('an interface destructor MUST NOT prevent the proxy declaration')
            ->toBeFalse();
        expect($this->doubles->callsTo($double, 'close'))
            ->toBe([[]]);
    }
}
