<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Attribute;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\SkipUnless;
use Greenlight\Attribute\Test;
use Greenlight\Core\Condition;
use Greenlight\Expect\Expect;

final class SkipUnlessTest
{
    #[Test]
    #[DataSet('invalidConditionClasses')]
    public function invalidConditionClassesAreRejected(string $condition): void
    {
        Expect::that(
            static fn(): SkipUnless => new SkipUnless($condition),
        )
            ->because('a skip condition MUST name an instantiable Condition class')
            ->toThrow(
                \InvalidArgumentException::class,
                message: 'SkipUnless condition MUST name an instantiable Condition class.',
            );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidConditionClasses(): iterable
    {
        yield 'empty' => [''];

        yield 'non-condition class' => [\stdClass::class];

        yield 'unknown class' => ['Example\MissingCondition'];

        yield 'condition interface' => [Condition::class];
    }
}
