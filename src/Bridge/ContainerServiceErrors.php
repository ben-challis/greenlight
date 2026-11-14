<?php

declare(strict_types=1);

namespace Greenlight\Bridge;

use Greenlight\Harness\ServiceResolutionFailed;

/**
 * Creates the service resolution errors of one framework bridge.
 *
 * `ContainerServiceResolver` calls these factories. Each bridge keeps its own
 * error class and message text.
 *
 * @internal
 */
interface ContainerServiceErrors
{
    public static function unknownServiceId(string $id, string $type): ServiceResolutionFailed;

    public static function serviceTypeMismatch(string $id, string $type, mixed $actual): ServiceResolutionFailed;

    public static function serviceResolutionFailed(
        string $id,
        string $type,
        \Throwable $previous,
        ContainerCall $call = ContainerCall::Get,
    ): ServiceResolutionFailed;
}
