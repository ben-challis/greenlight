<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Attribute;

use Greenlight\Attribute\After;
use Greenlight\Attribute\Before;
use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Group;
use Greenlight\Attribute\Isolated;
use Greenlight\Attribute\Retry;
use Greenlight\Attribute\Skip;
use Greenlight\Attribute\SkipUnless;
use Greenlight\Attribute\Test;
use Greenlight\Attribute\Timeout;
use Greenlight\Expect\Expect;

final class AttributeContractTest
{
    #[Test]
    public function methodOnlyAttributesTargetMethods(): void
    {
        foreach ([Test::class, Before::class, After::class, DataSet::class] as $attribute) {
            Expect::that($this->flags($attribute))->toBe(\Attribute::TARGET_METHOD);
        }
    }

    #[Test]
    public function methodOrClassAttributesTargetBoth(): void
    {
        foreach ([Skip::class, SkipUnless::class, Retry::class, Timeout::class, Isolated::class] as $attribute) {
            Expect::that($this->flags($attribute))->toBe(\Attribute::TARGET_METHOD | \Attribute::TARGET_CLASS);
        }
    }

    #[Test]
    public function groupIsRepeatableOnMethodsAndClasses(): void
    {
        Expect::that($this->flags(Group::class))
            ->toBe(\Attribute::TARGET_METHOD | \Attribute::TARGET_CLASS | \Attribute::IS_REPEATABLE);
    }

    #[Test]
    public function retryRejectsZeroTimes(): void
    {
        Expect::that(static fn(): Retry => new Retry(0))->toThrow(\InvalidArgumentException::class);
    }

    #[Test]
    public function timeoutRejectsNonPositiveSeconds(): void
    {
        Expect::that(static fn(): Timeout => new Timeout(0.0))->toThrow(\InvalidArgumentException::class);
        Expect::that(static fn(): Timeout => new Timeout(-1.5))->toThrow(\InvalidArgumentException::class);
    }

    /**
     * @param class-string $attributeClass
     */
    private function flags(string $attributeClass): int
    {
        $attributes = new \ReflectionClass($attributeClass)->getAttributes(\Attribute::class);
        Expect::that($attributes)->toHaveCount(1);

        return $attributes[0]->newInstance()->flags;
    }
}
