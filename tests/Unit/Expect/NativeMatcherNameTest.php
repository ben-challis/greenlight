<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Expect;

use Greenlight\Attribute\DataRow;
use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Expect\ExpectationExtensionError;
use Greenlight\Tests\Fixture\Expect\EvenNumbersExtension;
use Greenlight\Tests\Fixture\PhpStanNativeMatcherOverride\NativeMatcherOverrideExtension;

use function Greenlight\expect;

final class NativeMatcherNameTest
{
    /** @param non-empty-string $name */
    #[Test]
    #[DataRow(['toBeInt'], label: 'native matcher')]
    #[DataRow(['TOBEINT'], label: 'case variant')]
    #[DataRow(['not'], label: 'negation')]
    #[DataRow(['because'], label: 'reason')]
    public function rejectsNativeNamesWithoutReplacingInstalledExtensions(string $name): void
    {
        $restore = Expect::install([new EvenNumbersExtension()]);

        try {
            expect()->calling(static fn() => Expect::install([new NativeMatcherOverrideExtension($name)]))
                ->toThrow(
                    ExpectationExtensionError::class,
                    message: \sprintf(
                        'Extension matcher "%s" conflicts with a native expectation method. Rename the extension matcher.',
                        $name,
                    ),
                );
            expect(4)->toBeEven();
        } finally {
            $restore();
        }
    }
}
