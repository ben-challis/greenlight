<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Doubles;

use Greenlight\Attribute\Test;
use Greenlight\Doubles\Doubles;
use Greenlight\Doubles\MockPlan;
use Greenlight\Expect\Expect;
use Greenlight\Tests\Fixture\Doubles\InheritedPrivateConstantDefault;
use Greenlight\Tests\Fixture\Doubles\PrivateConstantDefault;

final readonly class ProxyPrivateConstantDefaultTest
{
    public function __construct(private Doubles $doubles) {}

    #[Test]
    public function privateSelfDefaultsAllowOmittedArguments(): void
    {
        Expect::that(new PrivateConstantDefault()->mode())->toBe('fast');
        $double = $this->doubles->mock(PrivateConstantDefault::class, static function (MockPlan $plan): void {
            $plan->expects('mode')->once()->andReturns('answered');
        });

        Expect::that($double->mode())->toBe('answered');
    }

    #[Test]
    public function privateQualifiedDefaultsAllowOmittedArguments(): void
    {
        Expect::that(new PrivateConstantDefault()->options())->toBe(['mode' => 'fast']);
        $double = $this->doubles->mock(PrivateConstantDefault::class, static function (MockPlan $plan): void {
            $plan->expects('options')->once()->andReturns(['mode' => 'answered']);
        });

        Expect::that($double->options())->toBe(['mode' => 'answered']);
    }

    #[Test]
    public function inheritedMethodsKeepTheirPrivateDefaults(): void
    {
        Expect::that(new InheritedPrivateConstantDefault()->mode())->toBe('fast');
        $double = $this->doubles->mock(InheritedPrivateConstantDefault::class, static function (MockPlan $plan): void {
            $plan->expects('mode')->once()->andReturns('answered');
        });

        Expect::that($double->mode())->toBe('answered');
    }
}
