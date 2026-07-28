<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Expect;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Tests\Fixture\Expect\Node;

final readonly class CyclicEqualityTopologyTest
{
    #[Test]
    public function deepEqualityDistinguishesDifferentCycleTopologies(): void
    {
        $selfCycle = new Node();
        $selfCycle->next = $selfCycle;

        $first = new Node();
        $second = new Node();
        $first->next = $second;
        $second->next = $first;

        Expect::that($selfCycle)
            ->because('deep equality MUST preserve cyclic object relationships')
            ->not()
            ->toEqual($first);
    }

    #[Test]
    public function deepEqualityPreservesSharedObjectRelationships(): void
    {
        $shared = new \stdClass();
        $left = (object) ['first' => $shared, 'second' => $shared];
        $right = (object) ['first' => new \stdClass(), 'second' => new \stdClass()];

        Expect::that($left)
            ->because('deep equality MUST distinguish sharing one object from duplicating its state')
            ->not()
            ->toEqual($right);
    }
}
