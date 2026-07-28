<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Expect;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;

final class CanonicalObjectPropertyOrderTest
{
    #[Test]
    public function canonicalEqualityIgnoresObjectPropertyInsertionOrder(): void
    {
        $first = $this->objectWithProperties(['a' => 1, 'b' => 2]);
        $second = $this->objectWithProperties(['a' => 2, 'b' => 1]);
        $equivalentFirst = $this->objectWithProperties(['b' => 2, 'a' => 1]);
        $equivalentSecond = $this->objectWithProperties(['b' => 1, 'a' => 2]);

        Expect::that([$first, $second])
            ->because('canonical equality MUST ignore object property insertion order')
            ->toEqualCanonicalizing([$equivalentFirst, $equivalentSecond]);
    }

    /** @param array<string, int> $properties */
    private function objectWithProperties(array $properties): \stdClass
    {
        $object = new \stdClass();

        foreach ($properties as $name => $value) {
            $object->{$name} = $value;
        }

        return $object;
    }
}
