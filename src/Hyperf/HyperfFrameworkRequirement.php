<?php

declare(strict_types=1);

namespace Greenlight\Hyperf;

use Composer\InstalledVersions;
use Greenlight\Harness\ServiceResolutionFailed;
use Hyperf\Context\ApplicationContext;
use Hyperf\Di\ClassLoader;

/**
 * Checks the framework and coroutine prerequisites for the Hyperf bridge.
 *
 * @internal
 */
final class HyperfFrameworkRequirement
{
    /**
     * The isolated Hyperf acceptance job exercises this environment adapter.
     *
     * @codeCoverageIgnore
     * @throws ServiceResolutionFailed
     */
    public static function check(): void
    {
        self::checkEnvironment(
            \class_exists(ClassLoader::class) && \class_exists(ApplicationContext::class),
            InstalledVersions::isInstalled('hyperf/framework')
                ? InstalledVersions::getPrettyVersion('hyperf/framework')
                : null,
            \phpversion('swoole'),
            \function_exists('pcntl_fork'),
        );
    }

    /** @throws ServiceResolutionFailed */
    public static function checkEnvironment(
        bool $frameworkAvailable,
        ?string $frameworkVersion,
        string|false $swooleVersion,
        bool $pcntlAvailable,
    ): void {
        if (!$frameworkAvailable) {
            throw HyperfBridgeError::frameworkUnavailable();
        }

        self::checkFrameworkVersion($frameworkVersion);
        self::checkSwooleVersion($swooleVersion);

        if (!$pcntlAvailable) {
            throw HyperfBridgeError::pcntlUnavailable();
        }
    }

    /** @throws ServiceResolutionFailed */
    public static function checkFrameworkVersion(?string $version): void
    {
        if ($version === null) {
            throw HyperfBridgeError::frameworkUnavailable();
        }

        if (\preg_match('/^v?3\.2(?:\.|$)/D', $version) !== 1) {
            throw HyperfBridgeError::frameworkVersionUnsupported($version);
        }
    }

    /** @throws ServiceResolutionFailed */
    public static function checkSwooleVersion(string|false $version): void
    {
        if ($version === false) {
            throw HyperfBridgeError::swooleUnavailable();
        }

        if (\preg_match('/^(?:[5-9]|[1-9][0-9]+)(?:\.|$)/D', $version) !== 1) {
            throw HyperfBridgeError::swooleVersionUnsupported($version);
        }
    }
}
