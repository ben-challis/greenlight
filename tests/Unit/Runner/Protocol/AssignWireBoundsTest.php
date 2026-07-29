<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Runner\Protocol;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Discovery\ExecutionPlan;
use Greenlight\Expect\Expect;
use Greenlight\Runner\Protocol\Messages\Assign;

final readonly class AssignWireBoundsTest
{
    #[Test]
    #[DataSet('nonpositiveRecycleLimits')]
    public function nonpositiveRecycleLimitsNormalizeToOne(string $field, int $legacy): void
    {
        $payload = new Assign(new ExecutionPlan([]))->toWire();
        $payload[$field] = $legacy;
        $assign = Assign::fromWire($payload);

        Expect::that($assign->toWire()[$field])
            ->because('decoded worker recycle limits MUST be positive when set')
            ->toBe(1);
    }

    /**
     * @return iterable<string, array{non-empty-string, int}>
     */
    public static function nonpositiveRecycleLimits(): iterable
    {
        yield 'zero test limit' => ['recycleAfterTests', 0];
        yield 'negative test limit' => ['recycleAfterTests', -1];
        yield 'zero memory limit' => ['recycleAboveMemoryBytes', 0];
        yield 'negative memory limit' => ['recycleAboveMemoryBytes', -1];
    }
}
