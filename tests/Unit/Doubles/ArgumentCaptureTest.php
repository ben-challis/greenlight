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

final readonly class ArgumentCaptureTest
{
    public function __construct(private Doubles $doubles) {}

    #[Test]
    public function captureArgumentIgnoresOmittedOptionalAndVariadicPositions(): void
    {
        $optionalCaptor = null;
        $variadicCaptor = null;
        $wide = $this->doubles->mock(Wide::class, static function (MockPlan $plan) use (&$optionalCaptor, &$variadicCaptor): void {
            $optionalCaptor = $plan->expects('withDefaults')->once()->andReturns('defaults')->captureArgument(1);
            $variadicCaptor = $plan->expects('variadic')->once()->andReturns([])->captureArgument(1);
        });

        Expect::that($wide->withDefaults())
            ->because('capture argument ignores omitted optional and variadic positions')
            ->toBe('defaults');
        Expect::that($wide->variadic('head'))->toBe([]);

        if (!$optionalCaptor instanceof ArgumentCaptor || !$variadicCaptor instanceof ArgumentCaptor) {
            Fail::because('Expected both captureArgument() calls to return ArgumentCaptor.');
        }

        Expect::that($optionalCaptor->values())->toBe([]);
        Expect::that($variadicCaptor->values())->toBe([]);
    }
}
