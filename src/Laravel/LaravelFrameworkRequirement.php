<?php

declare(strict_types=1);

namespace Greenlight\Laravel;

use Illuminate\Foundation\Application;

/**
 * Checks whether LaravelPlugin can use Laravel.
 * The bridge supports major version 13.
 *
 * @internal
 */
final class LaravelFrameworkRequirement
{
    /**
     * @throws LaravelBridgeError
     */
    public static function check(): void
    {
        self::checkVersion(self::installedVersion());
    }

    /**
     * @throws LaravelBridgeError
     */
    public static function checkVersion(?string $version): void
    {
        if ($version === null) {
            throw LaravelBridgeError::frameworkUnavailable();
        }

        if (\preg_match('/^13(?:\\.|$)/D', $version) !== 1) {
            throw LaravelBridgeError::frameworkVersionUnsupported($version);
        }
    }

    private static function installedVersion(): ?string
    {
        if (!\class_exists(Application::class)) {
            return null;
        }

        return Application::VERSION;
    }
}
