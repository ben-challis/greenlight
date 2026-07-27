<?php

declare(strict_types=1);

namespace Greenlight\Fixture;

/**
 * Restores the original states of getenv(), $_ENV, and $_SERVER independently.
 *
 * @internal
 */
final readonly class EnvironmentBackup
{
    private function __construct(
        private string $name,
        private string|false $getenv,
        private bool $inEnv,
        private mixed $envValue,
        private bool $inServer,
        private mixed $serverValue,
    ) {}

    public static function capture(string $name): self
    {
        return new self(
            $name,
            \getenv($name),
            \array_key_exists($name, $_ENV),
            $_ENV[$name] ?? null,
            \array_key_exists($name, $_SERVER),
            $_SERVER[$name] ?? null,
        );
    }

    public function restore(): void
    {
        if ($this->getenv === false) {
            \putenv($this->name);
        } else {
            \putenv($this->name . '=' . $this->getenv);
        }

        if ($this->inEnv) {
            $_ENV[$this->name] = $this->envValue;
        } else {
            unset($_ENV[$this->name]);
        }

        if ($this->inServer) {
            $_SERVER[$this->name] = $this->serverValue;
        } else {
            unset($_SERVER[$this->name]);
        }
    }
}
