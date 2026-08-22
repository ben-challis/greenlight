<?php

declare(strict_types=1);

namespace Greenlight\Tempest;

use Greenlight\Internal\Process\EnvironmentBackup;
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
        private EnvironmentBackup $environment,
    ) {}

    /**
     * @throws \InvalidArgumentException If $environment contains a null byte.
     */
    public static function activate(string $environment, ?GenericContainer $container = null): self
    {
        if (\str_contains($environment, "\0")) {
            throw new \InvalidArgumentException('Tempest environment MUST NOT contain a null byte.');
        }

        $backup = EnvironmentBackup::capture('ENVIRONMENT');
        $state = new self(
            GenericContainer::instance(),
            $backup,
        );

        $backup->set($environment);

        if ($container instanceof GenericContainer) {
            GenericContainer::setInstance($container);
        }

        return $state;
    }

    public function restore(): void
    {
        GenericContainer::setInstance($this->container);

        $this->environment->restore();
    }
}
