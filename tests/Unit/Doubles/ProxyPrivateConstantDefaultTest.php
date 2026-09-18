<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Doubles;

use Greenlight\Attribute\Test;
use Greenlight\Doubles\Doubles;
use Greenlight\Doubles\MockPlan;
use Greenlight\Tests\Fixture\Doubles\InheritedPrivateConstantDefault;
use Greenlight\Tests\Fixture\Doubles\PrivateConstantDefault;

use function Greenlight\expect;

final readonly class ProxyPrivateConstantDefaultTest
{
    public function __construct(private Doubles $doubles) {}

    #[Test]
    public function privateSelfDefaultsAllowOmittedArguments(): void
    {
        expect(new PrivateConstantDefault()->mode())->toBe('fast');
        $double = $this->doubles->mock(PrivateConstantDefault::class, static function (MockPlan $plan): void {
            $plan->expects('mode')->once()->andReturns('answered');
        });

        expect($double->mode())->toBe('answered');
    }

    #[Test]
    public function privateQualifiedDefaultsAllowOmittedArguments(): void
    {
        expect(new PrivateConstantDefault()->options())->toBe(['mode' => 'fast']);
        $double = $this->doubles->mock(PrivateConstantDefault::class, static function (MockPlan $plan): void {
            $plan->expects('options')->once()->andReturns(['mode' => 'answered']);
        });

        expect($double->options())->toBe(['mode' => 'answered']);
    }

    #[Test]
    public function inheritedMethodsKeepTheirPrivateDefaults(): void
    {
        expect(new InheritedPrivateConstantDefault()->mode())->toBe('fast');
        $double = $this->doubles->mock(InheritedPrivateConstantDefault::class, static function (MockPlan $plan): void {
            $plan->expects('mode')->once()->andReturns('answered');
        });

        expect($double->mode())->toBe('answered');
    }
}
