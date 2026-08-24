<?php

declare(strict_types=1);

namespace Greenlight\Coverage;

/** @internal */
final class CoverageError extends \RuntimeException
{
    private function __construct(string $message)
    {
        parent::__construct($message);
    }

    public static function driverUnavailable(string $driver, string $hint): self
    {
        return new self(\sprintf('Coverage driver "%s" is not available. %s', $driver, $hint));
    }

    public static function invalidJson(string $reason): self
    {
        return new self(\sprintf('Coverage JSON document is invalid: %s', $reason));
    }

    public static function sharedDirectoryCreationFailed(string $directory, ?string $cause): self
    {
        return new self(\sprintf(
            'Failed to create shared coverage directory "%s"%s.',
            $directory,
            $cause === null ? '' : ': ' . $cause,
        ));
    }

    public static function perTestIncludeRequired(): self
    {
        return new self('Per-test coverage requires at least one coverage include path. Configure CoverageBuilder::include() or use --coverage-include.');
    }

    public static function requiredDriverUnavailable(string $reason): self
    {
        return new self(\sprintf('Per-test coverage requires an available coverage driver: %s.', $reason));
    }

    public static function branchCoverageUnavailable(string $reason): self
    {
        return new self(\sprintf('Branch coverage requires Xdebug branch support: %s', $reason));
    }

    public static function branchCoverageRequiresXdebug(): self
    {
        return new self('Branch coverage requires the Xdebug coverage driver. Remove driver("pcov") or select driver("xdebug").');
    }

    public static function artifactWriteFailed(string $path, ?string $cause = null): self
    {
        return new self(\sprintf(
            'Greenlight could not write the per-test coverage artifact at "%s"%s.',
            $path,
            $cause === null ? '' : ': ' . $cause,
        ));
    }
}
