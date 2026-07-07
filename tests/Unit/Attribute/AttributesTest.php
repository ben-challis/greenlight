<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Attribute;

use Greenlight\Attribute\After;
use Greenlight\Attribute\Before;
use Greenlight\Attribute\Test;
use Greenlight\Tests\Support\Check;

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
        Check::true($this->beforeRan, 'the #[Before] hook to run before the test');
    }

    #[Test]
    public function attributesTargetMethods(): void
    {
        foreach ([Test::class, Before::class, After::class] as $attributeClass) {
            $attributes = new \ReflectionClass($attributeClass)->getAttributes(\Attribute::class);

            Check::same(1, \count($attributes), \sprintf('%s to be declared as an attribute', $attributeClass));

            $flags = $attributes[0]->newInstance()->flags;

            if ($flags !== \Attribute::TARGET_METHOD) {
                throw new \RuntimeException(\sprintf('%s must target methods only.', $attributeClass));
            }
        }
    }
}
