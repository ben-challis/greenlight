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
}
