<?php

declare(strict_types=1);

namespace Greenlight\Fixture;

use Greenlight\Harness\Disposable;

/**
 * Updates getenv(), $_ENV, and $_SERVER together. Each original value is
 * recorded once and restored on disposal.
 */
final class EnvironmentSandbox implements Disposable
{
    /**
     * @var array<string, EnvironmentBackup>
     */
    private array $originals = [];

    public function set(string $name, string $value): void
    {
        $this->record($name);

        \putenv($name . '=' . $value);
        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
    }

    public function unset(string $name): void
    {
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
}
