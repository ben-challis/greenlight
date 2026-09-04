<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\PhpStanNativeMatcherOverride;

use Greenlight\Expect\ExpectationExtension;

final class NativeMatcherOverrideExtension implements ExpectationExtension
{
    /** @param non-empty-string $name */
    public function __construct(private readonly string $name = 'toBeInt') {}

    /** @return array<non-empty-string, \Closure(string): string> */
    public function matchers(): array
    {
        return [
            $this->name => static fn(string $subject): string => $subject,
        ];
    }
}
