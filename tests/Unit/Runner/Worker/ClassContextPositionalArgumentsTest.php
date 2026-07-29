<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Runner\Worker;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Runner\Worker\ClassContext;
use Greenlight\Tests\Fixture\Runner\Worker\AssociativeDataSetProbe;

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
