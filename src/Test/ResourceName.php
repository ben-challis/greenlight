<?php

declare(strict_types=1);

namespace Greenlight\Test;

/**
 * Validates the standard names that connect test requirements to configured resource limits.
 *
 * @internal
 */
final class ResourceName
{
    public const string PATTERN = '[a-z0-9][a-z0-9._-]*';

    /** @codeCoverageIgnore */
    private function __construct() {}

    public static function isValid(string $name): bool
    {
        return \preg_match('/^' . self::PATTERN . '$/D', $name) === 1;
    }

    /**
     * @phpstan-assert non-empty-string $name
     *
     * @throws \InvalidArgumentException
     */
    public static function assertValid(string $name): void
    {
        if (!self::isValid($name)) {
            throw new \InvalidArgumentException(\sprintf(
                'Resource names must match %s, got "%s".',
                self::PATTERN,
                $name,
            ));
        }
    }
}
