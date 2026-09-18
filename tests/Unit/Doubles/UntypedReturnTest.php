<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Doubles;

use Greenlight\Attribute\Test;
use Greenlight\Doubles\Doubles;
use Greenlight\Doubles\MockPlan;
use Greenlight\Tests\Fixture\Doubles\UntypedAction;

use function Greenlight\expect;

final readonly class UntypedReturnTest
{
    public function __construct(private Doubles $doubles) {}

    #[Test]
    public function aPlannedUntypedMethodNeedsNoConfiguredReturnValue(): void
    {
        $action = $this->doubles->mock(
            UntypedAction::class,
            static function (MockPlan $plan): void {
                $plan
                    ->expects('perform')
                    ->with('value')
                    ->once();
            },
        );

        $result = $action->perform('value');

        expect($result)
            ->because('an untyped collaborator method can complete without a configured value')
            ->toBeNull();
    }
}
