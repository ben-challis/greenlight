<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Attribute;

use Greenlight\Attribute\After;
use Greenlight\Attribute\Before;
use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;

final class AttributesTest
{
    private bool $beforeRan = false;

    #[Before]
    public function markBefore(): void
    {
        $this->beforeRan = true;
    }

    #[Test]
    public function beforeHookRunsBeforeTests(): void
    {
        Expect::that($this->beforeRan)->toBeTrue();
    }

    #[Test]
    public function attributesTargetMethods(): void
    {
        foreach ([Test::class, Before::class, After::class] as $attributeClass) {
            $attributes = new \ReflectionClass($attributeClass)->getAttributes(\Attribute::class);

            Expect::that($attributes)->toHaveCount(1);

            $flags = $attributes[0]->newInstance()->flags;

            Expect::that($flags)->toBe(\Attribute::TARGET_METHOD);
        }
    }
}
