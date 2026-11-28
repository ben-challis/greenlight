<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Doubles;

use Greenlight\Attribute\Test;
use Greenlight\Doubles\Doubles;
use Greenlight\Doubles\MockPlan;
use Greenlight\Tests\Fixture\Doubles\UnqualifiedConstantDefault;

use function Greenlight\expect;

final readonly class ProxyUnqualifiedConstantDefaultTest
{
    public function __construct(private Doubles $doubles) {}

    #[Test]
    public function unqualifiedGlobalConstantDefaultsResolveInTheProxy(): void
    {
        $double = $this->doubles->mock(UnqualifiedConstantDefault::class, static function (MockPlan $plan): void {
            $plan->expects('largest')->once()->andReturns(1);
            $plan->expects('smallest')->once()->andReturns(2);
            $plan->expects('notANumber')->once()->andReturns(3.0);
            $plan->expects('infinite')->once()->andReturns(4.0);
            $plan->expects('pi')->once()->andReturns(5.0);
            $plan->expects('flags')->once()->andReturns(6);
            $plan->expects('lineEnd')->once()->andReturns('7');
        });

        expect([
            $double->largest(),
            $double->smallest(),
            $double->notANumber(),
            $double->infinite(),
            $double->pi(),
            $double->flags(),
            $double->lineEnd(),
        ])
            ->because('a call that omits an unqualified global constant default MUST NOT fail')
            ->toBe([1, 2, 3.0, 4.0, 5.0, 6, '7']);
    }

    #[Test]
    public function generatedDefaultsKeepTheGlobalConstantValues(): void
    {
        $double = $this->doubles->stub(UnqualifiedConstantDefault::class);
        $default = static fn(string $method): mixed => new \ReflectionMethod($double, $method)->getParameters()[0]->getDefaultValue();

        expect([
            $default('largest'),
            $default('smallest'),
            $default('infinite'),
            $default('pi'),
            $default('flags'),
            $default('lineEnd'),
        ])
            ->because('the proxy MUST use the global constant when the namespace does not define it')
            ->toBe([\PHP_INT_MAX, \PHP_INT_MIN, \INF, \M_PI, \JSON_THROW_ON_ERROR, \PHP_EOL]);
        $notANumber = $default('notANumber');

        expect(\is_float($notANumber) && \is_nan($notANumber))
            ->toBeTrue();
    }
}
