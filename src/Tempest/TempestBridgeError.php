<?php

declare(strict_types=1);

namespace Greenlight\Tempest;

use Greenlight\Harness\ServiceResolutionFailed;

/**
 * Reports a Tempest bridge configuration or run-time failure.
 *
 * @internal
 */
final class TempestBridgeError extends ServiceResolutionFailed
{
    private function __construct(string $message, ?\Throwable $previous = null)
    {
        parent::__construct($message, previous: $previous);
    }

    public static function frameworkUnavailable(): self
    {
        return new self(
            'The Tempest framework is not available. TempestPlugin requires '
            . 'tempest/framework 3.18 or later in major version 3. Install the framework '
            . 'before you activate the plugin.',
        );
    }

    public static function frameworkVersionUnsupported(string $version): self
    {
        return new self(\sprintf(
            'TempestPlugin found tempest/framework "%s", but it requires version 3.18 or later '
            . 'in major version 3. Install tempest/framework ^3.18.',
            $version,
        ));
    }

    public static function bootFailed(string $root, \Throwable $cause): self
    {
        return new self(\sprintf(
            'TempestPlugin could not boot the application at "%s": %s',
            $root,
            $cause->getMessage(),
        ), $cause);
    }

    public static function shutdownFailed(string $root, \Throwable $cause): self
    {
        return new self(\sprintf(
            'TempestPlugin could not shut down the application at "%s": %s',
            $root,
            $cause->getMessage(),
        ), $cause);
    }

    public static function serviceResolutionFailed(string $type, \Throwable $cause): self
    {
        return new self(\sprintf(
            'The Tempest container could not resolve the parameter type "%s": %s',
            $type,
            $cause->getMessage(),
        ), $cause);
    }

    public static function serviceTypeMismatch(string $type, mixed $actual): self
    {
        return new self(\sprintf(
            'The Tempest container returned "%s" for the parameter type "%s".',
            \get_debug_type($actual),
            $type,
        ));
    }
}
