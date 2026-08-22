<?php

declare(strict_types=1);

namespace Greenlight\Hyperf;

use Greenlight\Harness\ServiceResolutionFailed;

/**
 * Reports a Hyperf bridge configuration or runtime failure.
 *
 * @internal
 */
final class HyperfBridgeError extends ServiceResolutionFailed
{
    private function __construct(string $message)
    {
        parent::__construct($message);
    }

    public static function basePathMissing(string $path): self
    {
        return new self(\sprintf(
            'The Hyperf base path "%s" does not exist. Give HyperfPlugin the application root directory.',
            $path,
        ));
    }

    public static function basePathConflict(string $configured, string $defined): self
    {
        return new self(\sprintf(
            'HyperfPlugin uses base path "%s", but BASE_PATH is already "%s". Use one Hyperf application in each worker.',
            $configured,
            $defined,
        ));
    }

    public static function containerFileMissing(string $path): self
    {
        return new self(\sprintf(
            'The Hyperf container file "%s" does not exist. Add the standard config/container.php file.',
            $path,
        ));
    }

    public static function frameworkUnavailable(): self
    {
        return new self(
            'HyperfPlugin requires hyperf/framework and hyperf/di 3.2. Install both packages before you activate the plugin.',
        );
    }

    public static function frameworkVersionUnsupported(string $version): self
    {
        return new self(\sprintf(
            'HyperfPlugin found hyperf/framework "%s", but it requires version 3.2. Install hyperf/framework 3.2.',
            $version,
        ));
    }

    public static function swooleUnavailable(): self
    {
        return new self(
            'HyperfPlugin requires the Swoole extension. Install Swoole 5 or later to run tests in Hyperf coroutines.',
        );
    }

    public static function pcntlUnavailable(): self
    {
        return new self(
            'HyperfPlugin requires the pcntl extension for the Hyperf class scan. Enable pcntl for the Greenlight PHP command.',
        );
    }

    public static function swooleVersionUnsupported(string $version): self
    {
        return new self(\sprintf(
            'HyperfPlugin found Swoole "%s", but it requires major version 5 or later.',
            $version,
        ));
    }

    public static function scanLockUnavailable(string $path): self
    {
        return new self(\sprintf(
            'HyperfPlugin cannot open scan lock "%s". Make the runtime/container directory writable.',
            $path,
        ));
    }

    public static function scanLockFailed(string $path): self
    {
        return new self(\sprintf(
            'HyperfPlugin cannot lock "%s" for the class scan.',
            $path,
        ));
    }

    public static function notAContainer(string $path, string $actual): self
    {
        return new self(\sprintf(
            'The Hyperf container file "%s" returned "%s". It must return a Psr\\Container\\ContainerInterface instance.',
            $path,
            $actual,
        ));
    }

    public static function reusedContainer(): self
    {
        return new self(
            'The Hyperf container file returned the previous test container. It must create a new container for each test.',
        );
    }

    public static function applicationUnavailable(string $actual): self
    {
        return new self(\sprintf(
            'The Hyperf application binding returned "%s". The binding must return an application object.',
            $actual,
        ));
    }

    public static function coroutineDidNotStart(): self
    {
        return new self('Swoole did not start the test coroutine. Check the Swoole runtime configuration.');
    }

    public static function workerContainerUnavailable(): self
    {
        return new self(
            'The Hyperf worker container is not available. Greenlight cannot start the worker runtime.',
        );
    }

    public static function workerRuntimeUnavailable(): self
    {
        return new self(
            'The Hyperf worker runtime is not active. Run the test attempt inside WorkerRuntimeRunner.',
        );
    }

    public static function containerOutsideAttempt(): self
    {
        return new self(
            'The Hyperf container is not active. Resolve Hyperf services only during a Greenlight test attempt.',
        );
    }

    public static function unknownServiceId(string $id, string $type): self
    {
        return new self(\sprintf(
            'The Hyperf container has no service "%s" for type "%s". Check the service ID.',
            $id,
            $type,
        ));
    }

    public static function serviceTypeMismatch(string $id, string $type, string $actual): self
    {
        return new self(\sprintf(
            'The Hyperf service "%s" has type "%s". The parameter requires type "%s".',
            $id,
            $actual,
            $type,
        ));
    }
}
