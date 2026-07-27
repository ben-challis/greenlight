<?php

declare(strict_types=1);

namespace Greenlight\Laravel;

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
        private string|false $processEnvironment,
        private bool $environmentWasSet,
        private mixed $environment,
        private bool $serverEnvironmentWasSet,
        private mixed $serverEnvironment,
    ) {}

    public static function setEnvironment(string $environment): self
    {
        $state = new self(
            Container::getInstance(),
            Facade::getFacadeApplication(),
            \getenv('APP_ENV'),
            \array_key_exists('APP_ENV', $_ENV),
            $_ENV['APP_ENV'] ?? null,
            \array_key_exists('APP_ENV', $_SERVER),
            $_SERVER['APP_ENV'] ?? null,
        );

        \putenv('APP_ENV=' . $environment);
        $_ENV['APP_ENV'] = $environment;
        $_SERVER['APP_ENV'] = $environment;

        return $state;
    }

    public function restore(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->facadeApplication);
        Container::setInstance($this->container);

        if ($this->processEnvironment === false) {
            \putenv('APP_ENV');
        } else {
            \putenv('APP_ENV=' . $this->processEnvironment);
        }

        if ($this->environmentWasSet) {
            $_ENV['APP_ENV'] = $this->environment;
        } else {
            unset($_ENV['APP_ENV']);
        }

        if ($this->serverEnvironmentWasSet) {
            $_SERVER['APP_ENV'] = $this->serverEnvironment;
        } else {
            unset($_SERVER['APP_ENV']);
        }
    }
}
