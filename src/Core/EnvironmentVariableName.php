<?php

declare(strict_types=1);

namespace Greenlight\Core;

/** @internal */
final class EnvironmentVariableName
{
    /** @codeCoverageIgnore */
    private function __construct() {}

    /**
     * @phpstan-assert non-empty-string $name
     *
     * @throws \InvalidArgumentException
     */
    public static function assertValid(string $name): void
    {
        if ($name === '' || \str_contains($name, '=') || \str_contains($name, "\0")) {
            throw new \InvalidArgumentException(
                'Environment variable names cannot be empty or contain "=" or a null byte.',
            );
        }
    }
}
