<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Doubles;

use Greenlight\Attribute\Test;
use Greenlight\Doubles\Doubles;
use Greenlight\Doubles\MockPlan;
use Greenlight\Expect\Expect;
use Greenlight\Tests\Fixture\Doubles\UntypedAction;

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

        Expect::that($result)
            ->because('an untyped collaborator method can complete without a configured value')
            ->toBeNull();
    }
}
