<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\PhpStanNativeMatcherOverride;

use Greenlight\Expect\ExpectationExtension;

final class NativeMatcherOverrideExtension implements ExpectationExtension
{
    public function matchers(): array
    {
        return [
            'toBeInt' => static fn(string $subject): string => $subject,
            'toHavePositiveValue' => static fn(int $subject): bool => $subject > 0,
        ];
    }
}
