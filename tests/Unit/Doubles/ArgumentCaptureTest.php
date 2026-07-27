<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Doubles;

use Greenlight\Attribute\Test;
use Greenlight\Doubles\ArgumentCaptor;
use Greenlight\Doubles\Doubles;
use Greenlight\Doubles\MockPlan;
use Greenlight\Expect\Expect;
use Greenlight\Expect\Fail;
use Greenlight\Tests\Fixture\Doubles\Wide;

final class ArgumentCaptureTest
{
    #[Test]
    public function captureArgumentIgnoresOmittedOptionalAndVariadicPositions(): void
    {
        $doubles = new Doubles();
        $optionalCaptor = null;
        $variadicCaptor = null;
        $wide = $doubles->mock(Wide::class, static function (MockPlan $plan) use (&$optionalCaptor, &$variadicCaptor): void {
            $optionalCaptor = $plan->expects('withDefaults')->once()->andReturns('defaults')->captureArgument(1);
            $variadicCaptor = $plan->expects('variadic')->once()->andReturns([])->captureArgument(1);
        });

        Expect::that($wide->withDefaults())
            ->because('capture argument ignores omitted optional and variadic positions')
            ->toBe('defaults')
            ->and($wide->variadic('head'))->toBe([]);

        if (!$optionalCaptor instanceof ArgumentCaptor || !$variadicCaptor instanceof ArgumentCaptor) {
            Fail::because('Expected both captureArgument() calls to return ArgumentCaptor.');
        }

        Expect::that($optionalCaptor->values())->toBe([])
            ->and($variadicCaptor->values())->toBe([]);

        $doubles->dispose();
    }
}
