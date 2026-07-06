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
use Greenlight\Tests\Support\Check;

final class AttributeContractTest
{
    #[Test]
    public function methodOnlyAttributesTargetMethods(): void
    {
        foreach ([Test::class, Before::class, After::class, DataSet::class] as $attribute) {
            Check::same(\Attribute::TARGET_METHOD, $this->flags($attribute), $attribute . ' target');
        }
    }

    #[Test]
    public function methodOrClassAttributesTargetBoth(): void
    {
        foreach ([Skip::class, SkipUnless::class, Retry::class, Timeout::class, Isolated::class] as $attribute) {
            Check::same(
                \Attribute::TARGET_METHOD | \Attribute::TARGET_CLASS,
                $this->flags($attribute),
                $attribute . ' target',
            );
        }
    }

    #[Test]
    public function groupIsRepeatableOnMethodsAndClasses(): void
    {
        Check::same(
            \Attribute::TARGET_METHOD | \Attribute::TARGET_CLASS | \Attribute::IS_REPEATABLE,
            $this->flags(Group::class),
            'Group target',
        );
    }

    #[Test]
    public function retryRejectsZeroTimes(): void
    {
        Check::throws(static fn(): Retry => new Retry(0), \InvalidArgumentException::class, 'Retry(0)');
    }

    #[Test]
    public function timeoutRejectsNonPositiveSeconds(): void
    {
        Check::throws(static fn(): Timeout => new Timeout(0.0), \InvalidArgumentException::class, 'Timeout(0.0)');
        Check::throws(static fn(): Timeout => new Timeout(-1.5), \InvalidArgumentException::class, 'Timeout(-1.5)');
    }

    /**
     * @param class-string $attributeClass
     */
    private function flags(string $attributeClass): int
    {
        $attributes = new \ReflectionClass($attributeClass)->getAttributes(\Attribute::class);
        Check::same(1, \count($attributes), $attributeClass . ' attribute declaration count');

        return $attributes[0]->newInstance()->flags;
    }
}
