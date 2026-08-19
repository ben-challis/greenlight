<?php

declare(strict_types=1);

namespace Greenlight\Fixture;

use Greenlight\Core\EnvironmentBackup as CoreEnvironmentBackup;

/**
 * Restores the original states of getenv(), $_ENV, and $_SERVER independently.
 *
 * @internal
 */
final readonly class EnvironmentBackup
{
    private function __construct(private CoreEnvironmentBackup $backup) {}

    public static function capture(string $name): self
    {
        return new self(CoreEnvironmentBackup::capture($name));
    }

    public function restore(): void
    {
        $this->backup->restore();
    }
}
