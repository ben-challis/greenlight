<?php

declare(strict_types=1);

namespace Greenlight\Tempest;

use Tempest\Container\GenericContainer;

/**
 * Records process state that the Tempest kernel uses. restore() returns each
 * recorded value after one test attempt ends.
 *
 * @internal
 */
final readonly class TempestProcessState
{
    private function __construct(// @codeCoverageIgnore
        private ?GenericContainer $container,
        private string|false $processEnvironment,
        private bool $environmentWasSet,
        private mixed $environment,
        private bool $serverEnvironmentWasSet,
        private mixed $serverEnvironment,
    ) {}

    /**
     * @throws \InvalidArgumentException If $environment contains a null byte.
     */
    public static function activate(string $environment, ?GenericContainer $container = null): self
    {
        if (\str_contains($environment, "\0")) {
            throw new \InvalidArgumentException('Tempest environment MUST NOT contain a null byte.');
        }

        $state = new self(
            GenericContainer::instance(),
            \getenv('ENVIRONMENT'),
            \array_key_exists('ENVIRONMENT', $_ENV),
            $_ENV['ENVIRONMENT'] ?? null,
            \array_key_exists('ENVIRONMENT', $_SERVER),
            $_SERVER['ENVIRONMENT'] ?? null,
        );

        \putenv('ENVIRONMENT=' . $environment);
        $_ENV['ENVIRONMENT'] = $environment;
        $_SERVER['ENVIRONMENT'] = $environment;

        if ($container instanceof GenericContainer) {
            GenericContainer::setInstance($container);
        }

        return $state;
    }

    public function restore(): void
    {
        GenericContainer::setInstance($this->container);

        if ($this->processEnvironment === false) {
            \putenv('ENVIRONMENT');
        } else {
            \putenv('ENVIRONMENT=' . $this->processEnvironment);
        }

        if ($this->environmentWasSet) {
            $_ENV['ENVIRONMENT'] = $this->environment;
        } else {
            unset($_ENV['ENVIRONMENT']);
        }

        if ($this->serverEnvironmentWasSet) {
            $_SERVER['ENVIRONMENT'] = $this->serverEnvironment;
        } else {
            unset($_SERVER['ENVIRONMENT']);
        }
    }
}
