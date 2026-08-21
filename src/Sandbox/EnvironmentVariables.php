<?php

declare(strict_types=1);

namespace Greenlight\Sandbox;

use Greenlight\Core\EnvironmentBackup;
use Greenlight\Harness\Disposable;

/**
 * Updates `getenv()`, `$_ENV`, and `$_SERVER` together. It records each original
 * value one time and restores the value during disposal.
 */
final class EnvironmentVariables implements Disposable
{
    /**
     * @var array<string, EnvironmentBackup>
     */
    private array $originals = [];

    /**
     * @throws \InvalidArgumentException If $name is invalid or $value contains a null byte.
     */
    public function set(string $name, string $value): void
    {
        $this->backup($name)->set($value);
    }

    /**
     * @throws \InvalidArgumentException If $name is empty or contains "=" or a null byte.
     */
    public function unset(string $name): void
    {
        $this->backup($name)->unset();
    }

    #[\Override]
    public function dispose(): void
    {
        foreach ($this->originals as $backup) {
            $backup->restore();
        }

        $this->originals = [];
    }

    private function backup(string $name): EnvironmentBackup
    {
        return $this->originals[$name] ??= EnvironmentBackup::capture($name);
    }
}
