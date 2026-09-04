<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Expect;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;

final class JsonContainerShapeTest
{
    #[Test]
    public function emptyObjectsDifferFromEmptyArrays(): void
    {
        Expect::that('{}')->not()->toMatchJson('[]');
        Expect::that('[]')->not()->toMatchJson('{}');
    }

    #[Test]
    public function numericObjectKeysDoNotBecomeArrayIndices(): void
    {
        Expect::that('{"0":"first","1":"second"}')
            ->not()->toMatchJson('["first","second"]');
    }

    #[Test]
    public function nestedContainersPreserveTheirTypes(): void
    {
        Expect::that('{"items":{}}')->not()->toMatchJson('{"items":[]}');
        Expect::that('[{}]')->not()->toMatchJson('[[]]');
    }

    #[Test]
    public function objectKeyOrderDoesNotAffectEquality(): void
    {
        Expect::that('{"items":[],"options":{}}')
            ->toMatchJson('{"options":{},"items":[]}');
    }
}
