<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Execution\Worker;

use Greenlight\Attribute\Test;
use Greenlight\Execution\Worker\ClassContext;
use Greenlight\Expect\Expect;
use Greenlight\Tests\Fixture\Execution\Worker\AssociativeDataSetProbe;

final readonly class ClassContextPositionalArgumentsTest
{
    #[Test]
    public function associativeProviderRowsBecomePositionalArguments(): void
    {
        $arguments = ClassContext::for(AssociativeDataSetProbe::class)->argumentsFor(
            'associativeRows',
            null,
            'accepts',
            'sterling',
        );

        Expect::that($arguments)
            ->because('provider row keys MUST NOT become named test arguments')
            ->toBe(['GBP', 100]);
    }
}
