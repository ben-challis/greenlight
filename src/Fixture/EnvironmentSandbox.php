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
     * @var array<string, array{getenv: string|false, env: array{bool, mixed}, server: array{bool, mixed}}>
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
        foreach ($this->originals as $name => $original) {
            if ($original['getenv'] === false) {
                \putenv($name);
            } else {
                \putenv($name . '=' . $original['getenv']);
            }

            [$inEnv, $envValue] = $original['env'];

            if ($inEnv) {
                $_ENV[$name] = $envValue;
            } else {
                unset($_ENV[$name]);
            }

            [$inServer, $serverValue] = $original['server'];

            if ($inServer) {
                $_SERVER[$name] = $serverValue;
            } else {
                unset($_SERVER[$name]);
            }
        }

        $this->originals = [];
    }

    private function record(string $name): void
    {
        if (\array_key_exists($name, $this->originals)) {
            return;
        }

        $this->originals[$name] = [
            'getenv' => \getenv($name),
            'env' => [\array_key_exists($name, $_ENV), $_ENV[$name] ?? null],
            'server' => [\array_key_exists($name, $_SERVER), $_SERVER[$name] ?? null],
        ];
    }
}
