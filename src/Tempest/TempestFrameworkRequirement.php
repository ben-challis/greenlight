<?php

declare(strict_types=1);

namespace Greenlight\Tempest;

use Tempest\Container\Container;
use Tempest\Container\GenericContainer;
use Tempest\Core\FrameworkKernel;
use Tempest\Core\Kernel;

/**
 * Checks whether TempestPlugin can use the Tempest long-running kernel.
 * The bridge supports Tempest 3.18 and later releases in major version 3.
 *
 * @internal
 */
final class TempestFrameworkRequirement
{
    /**
     * @throws TempestBridgeError
     */
    public static function check(): void
    {
        if (!\class_exists(FrameworkKernel::class)
            || !\interface_exists(Kernel::class)
            || !\interface_exists(Container::class)
            || !\class_exists(GenericContainer::class)
        ) {
            throw TempestBridgeError::frameworkUnavailable(); // @codeCoverageIgnore
        }

        self::checkVersion(Kernel::VERSION);
    }

    /**
     * @throws TempestBridgeError
     */
    public static function checkVersion(?string $version): void
    {
        if ($version === null) {
            throw TempestBridgeError::frameworkUnavailable();
        }

        if (\version_compare($version, '3.18.0', '<') || \version_compare($version, '4.0.0', '>=')) {
            throw TempestBridgeError::frameworkVersionUnsupported($version);
        }
    }
}
