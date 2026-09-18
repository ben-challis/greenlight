<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Doubles;

use Greenlight\Attribute\Test;
use Greenlight\Doubles\ArgumentCaptor;
use Greenlight\Doubles\Doubles;
use Greenlight\Doubles\MockPlan;
use Greenlight\Tests\Fixture\Doubles\Wide;

use function Greenlight\expect;

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

        expect($wide->withDefaults())
            ->because('capture argument ignores omitted optional and variadic positions')
            ->toBe('defaults');
        expect($wide->variadic('head'))->toBe([]);

        expect($optionalCaptor)
            ->because('The optional captureArgument() call MUST return ArgumentCaptor.')
            ->toBeInstanceOf(ArgumentCaptor::class);
        expect($variadicCaptor)
            ->because('The variadic captureArgument() call MUST return ArgumentCaptor.')
            ->toBeInstanceOf(ArgumentCaptor::class);

        expect($optionalCaptor->values())->toBe([]);
        expect($variadicCaptor->values())->toBe([]);
    }
}
