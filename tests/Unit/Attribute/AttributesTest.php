<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Attribute;

use Greenlight\Attribute\After;
use Greenlight\Attribute\Before;
use Greenlight\Attribute\DataSet;
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
        Expect::that($this->beforeRan)->because('before hook runs before tests')->toBeTrue();
    }

    /**
     * @param class-string<Test|Before|After> $attributeClass
     */
    #[Test]
    #[DataSet('methodAttributes')]
    public function attributesTargetMethods(string $attributeClass): void
    {
        $attributes = new \ReflectionClass($attributeClass)->getAttributes(\Attribute::class);

        Expect::that($attributes)->toHaveCount(1);

        $flags = $attributes[0]->newInstance()->flags;

        Expect::that($flags)->toBe(\Attribute::TARGET_METHOD);
    }

    /**
     * @return iterable<string, array{class-string<Test|Before|After>}>
     */
    public static function methodAttributes(): iterable
    {
        yield 'Test' => [Test::class];
        yield 'Before' => [Before::class];
        yield 'After' => [After::class];
    }
}
