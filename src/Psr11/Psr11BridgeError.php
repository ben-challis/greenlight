<?php

declare(strict_types=1);

namespace Greenlight\Psr11;

use Greenlight\Harness\ServiceResolutionFailed;

/**
 * Reports a PSR-11 bridge configuration or run-time failure.
 *
 * @internal
 */
final class Psr11BridgeError extends ServiceResolutionFailed
{
    private function __construct(string $message, ?\Throwable $previous = null)
    {
        parent::__construct($message, previous: $previous);
    }

    public static function factoryFailed(\Throwable $previous): self
    {
        return new self(
            'The PSR-11 container factory failed. Check the container configuration.',
            $previous,
        );
    }

    public static function notAContainer(string $actual): self
    {
        return new self(\sprintf(
            'The PSR-11 container factory returned "%s". Return an instance of Psr\\Container\\ContainerInterface.',
            $actual,
        ));
    }

    public static function serviceCheckFailed(string $id, \Throwable $previous): self
    {
        return new self(
            \sprintf('The PSR-11 container failed when it checked service "%s". Check the container configuration.', $id),
            $previous,
        );
    }

    public static function serviceReadFailed(string $id, \Throwable $previous): self
    {
        return new self(
            \sprintf('The PSR-11 container failed when it read service "%s". Check the container configuration.', $id),
            $previous,
        );
    }

    public static function unknownServiceId(string $id, string $type): self
    {
        return new self(\sprintf(
            'The PSR-11 container has no service "%s" for the parameter of type "%s". Check the service ID.',
            $id,
            $type,
        ));
    }

    public static function serviceTypeMismatch(string $id, string $type, string $actual): self
    {
        return new self(\sprintf(
            'PSR-11 service "%s" has type "%s". The parameter requires type "%s".',
            $id,
            $actual,
            $type,
        ));
    }

    public static function resetFailed(\Throwable $previous): self
    {
        return new self(
            'The PSR-11 container reset failed. Check the reset callback.',
            $previous,
        );
    }
}
