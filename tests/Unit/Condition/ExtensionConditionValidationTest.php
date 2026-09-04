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
    /** @param \Closure(): (ExtensionLoaded|ExtensionMissing) $create */
    #[Test]
    #[DataSet('extensionConditions')]
    public function rejectsAnEmptyExtensionName(\Closure $create): void
    {
        Expect::that($create)
            ->because('an extension availability condition MUST identify the extension')
            ->toThrow(
                \InvalidArgumentException::class,
                message: 'Extension name cannot be empty.',
            );
    }

    /**
     * @return iterable<string, array{\Closure(): (ExtensionLoaded|ExtensionMissing)}>
     */
    public static function extensionConditions(): iterable
    {
        yield 'loaded' => [static fn(): ExtensionLoaded => new ExtensionLoaded('')]; // @phpstan-ignore argument.type (deliberately invalid: tests runtime validation)

        yield 'missing' => [static fn(): ExtensionMissing => new ExtensionMissing('')]; // @phpstan-ignore argument.type (deliberately invalid: tests runtime validation)
    }
}
