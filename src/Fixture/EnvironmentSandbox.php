<?php

declare(strict_types=1);

namespace Greenlight\Fixture;

use Greenlight\Harness\Disposable;

/**
 * Updates `getenv()`, `$_ENV`, and `$_SERVER` together. It records each original
 * value one time and restores the value during disposal.
 */
final class EnvironmentSandbox implements Disposable
{
    /**
     * @var array<string, EnvironmentBackup>
     */
    private array $originals = [];

    /**
     * @throws \InvalidArgumentException If $name is empty or contains "=" or a null byte.
     */
    public function set(string $name, string $value): void
    {
        $this->validateName($name);
        $this->record($name);

        \putenv($name . '=' . $value);
        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
    }

    /**
     * @throws \InvalidArgumentException If $name is empty or contains "=" or a null byte.
     */
    public function unset(string $name): void
    {
        $this->validateName($name);
        $this->record($name);

        \putenv($name);
        unset($_ENV[$name], $_SERVER[$name]);
    }

    #[\Override]
    public function dispose(): void
    {
        foreach ($this->originals as $backup) {
            $backup->restore();
        }

        $this->originals = [];
    }

    private function record(string $name): void
    {
        $this->originals[$name] ??= EnvironmentBackup::capture($name);
    }

    private function validateName(string $name): void
    {
        if ($name === '' || \str_contains($name, '=') || \str_contains($name, "\0")) {
            throw new \InvalidArgumentException(
                'Environment variable names cannot be empty or contain "=" or a null byte.',
            );
        }
    }
}
