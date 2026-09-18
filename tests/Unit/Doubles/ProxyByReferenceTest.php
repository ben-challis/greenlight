<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Doubles;

use Greenlight\Attribute\Test;
use Greenlight\Doubles\Doubles;
use Greenlight\Doubles\MockPlan;
use Greenlight\Tests\Fixture\Doubles\Wide;

use function Greenlight\expect;

final readonly class ProxyByReferenceTest
{
    public function __construct(private Doubles $doubles) {}

    #[Test]
    public function configuredCallbacksCanChangeByReferenceArguments(): void
    {
        $wide = $this->doubles->mock(Wide::class, static function (MockPlan $plan): void {
            $plan->expects('byReference')
                ->andReturnsUsing(static function (array &$items): void {
                    $items[] = 'changed';
                });
        });
        $items = ['original'];

        $wide->byReference($items);

        expect($items)
            ->because('a doubled method MUST preserve by-reference argument changes')
            ->toBe(['original', 'changed']);
    }
}
