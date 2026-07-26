<?php

declare(strict_types=1);

namespace Greenlight\Runner\Resource;

/**
 * Propagates held machine resources to nested Greenlight processes.
 *
 * @internal
 */
final class MachineResourceEnvironment
{
    private const string VARIABLE = 'GREENLIGHT_MACHINE_RESOURCE_LEASES';

    /** @codeCoverageIgnore */
    private function __construct() {}

    /**
     * @return list<non-empty-string>
     */
    public static function inherited(): array
    {
        $raw = \getenv(self::VARIABLE);

        if (!\is_string($raw) || $raw === '') {
            return [];
        }

        $decoded = \json_decode($raw, true);

        if (!\is_array($decoded) || !\array_is_list($decoded)) {
            return [];
        }

        return \array_values(\array_filter(
            $decoded,
            static fn(mixed $key): bool => \is_string($key) && $key !== '',
        ));
    }

    /**
     * @param list<non-empty-string> $keys
     */
    public static function set(array $keys): void
    {
        if ($keys === []) {
            \putenv(self::VARIABLE);

            return;
        }

        $encoded = \json_encode($keys);

        if (\is_string($encoded)) {
            \putenv(self::VARIABLE . '=' . $encoded);
        }
    }
}
