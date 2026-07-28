<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Condition;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Condition\ExtensionLoaded;
use Greenlight\Condition\ExtensionMissing;
use Greenlight\Expect\Expect;

final readonly class ExtensionConditionValidationTest
{
    /**
     * @param class-string<ExtensionLoaded|ExtensionMissing> $conditionClass
     */
    #[Test]
    #[DataSet('extensionConditions')]
    public function rejectsAnEmptyExtensionName(string $conditionClass): void
    {
        Expect::that(static fn(): ExtensionLoaded|ExtensionMissing => new $conditionClass(''))
            ->because('an extension availability condition MUST identify the extension')
            ->toThrow(
                \InvalidArgumentException::class,
                message: 'Extension name MUST NOT be empty.',
            );
    }

    /**
     * @return iterable<string, array{class-string<ExtensionLoaded|ExtensionMissing>}>
     */
    public static function extensionConditions(): iterable
    {
        yield 'loaded' => [ExtensionLoaded::class];

        yield 'missing' => [ExtensionMissing::class];
    }
}
