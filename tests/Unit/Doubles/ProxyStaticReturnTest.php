<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Doubles;

use Greenlight\Attribute\Test;
use Greenlight\Doubles\Doubles;
use Greenlight\Doubles\MockPlan;
use Greenlight\Expect\Expect;
use Greenlight\Tests\Fixture\Doubles\Wide;

final class ProxyStaticReturnTest
{
    #[Test]
    public function aConfiguredStaticReturnAcceptsTheGeneratedProxy(): void
    {
        $double = null;
        $doubles = new Doubles();

        try {
            $double = $doubles->mock(Wide::class, static function (MockPlan $plan) use (&$double): void {
                $plan->expects('returnsStatic')->andReturnsUsing(static function () use (&$double): ?Wide {
                    return $double;
                });
            });

            Expect::that($double->returnsStatic())
                ->because('a static return type MUST accept the generated proxy instance')
                ->toBe($double);
        } finally {
            $doubles->dispose();
        }
    }
}
