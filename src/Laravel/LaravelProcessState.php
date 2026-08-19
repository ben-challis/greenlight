<?php

declare(strict_types=1);

namespace Greenlight\Laravel;

use Greenlight\Core\EnvironmentBackup;
use Illuminate\Container\Container;
use Illuminate\Contracts\Container\Container as ContainerContract;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Facade;

/**
 * Records the process state that Laravel application construction changes.
 * restore() returns each recorded value after the application lifetime ends.
 *
 * @internal
 */
final readonly class LaravelProcessState
{
    private function __construct(
        private ContainerContract $container,
        private ?Application $facadeApplication,
        private EnvironmentBackup $environment,
    ) {}

    /**
     * @throws \InvalidArgumentException If $environment contains a null byte.
     */
    public static function setEnvironment(string $environment): self
    {
        if (\str_contains($environment, "\0")) {
            throw new \InvalidArgumentException('Laravel environment MUST NOT contain a null byte.');
        }

        $backup = EnvironmentBackup::capture('APP_ENV');
        $state = new self(
            Container::getInstance(),
            Facade::getFacadeApplication(),
            $backup,
        );

        $backup->set($environment);

        return $state;
    }

    public function restore(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->facadeApplication);
        Container::setInstance($this->container);

        $this->environment->restore();
    }
}
