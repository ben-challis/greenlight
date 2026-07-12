<?php

declare(strict_types=1);

namespace Greenlight\Fixture;

use Greenlight\Harness\Disposable;

/**
 * Scoped environment variable changes that undo themselves.
 *
 * set() and unset() apply to getenv(), $_ENV, and $_SERVER together, so all
 * three views stay in sync. The original state of each variable is recorded
 * exactly once, before its first modification, and dispose() restores every
 * touched variable to that recorded state, removing variables that did not
 * exist before.
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
