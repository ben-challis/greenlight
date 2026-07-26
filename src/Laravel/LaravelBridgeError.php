<?php

declare(strict_types=1);

namespace Greenlight\Laravel;

/** @internal */
final class LaravelBridgeError extends \RuntimeException
{
    private function __construct(string $message)
    {
        parent::__construct($message);
    }

    public static function bootstrapFileMissing(string $path): self
    {
        return new self(\sprintf(
            'The Laravel bootstrap file "%s" does not exist, so LaravelPlugin cannot create '
            . 'the application. Point LaravelPlugin at the file that returns the application, '
            . 'usually bootstrap/app.php.',
            $path,
        ));
    }

    public static function notAnApplication(string $actual): self
    {
        return new self(\sprintf(
            'The Laravel bootstrap returned "%s" instead of an '
            . 'Illuminate\Contracts\Foundation\Application instance, so LaravelPlugin cannot '
            . 'boot it. Return the application from the bootstrap file or the closure, '
            . 'usually the result of Application::configure(...)->create().',
            $actual,
        ));
    }

    public static function consoleKernelUnavailable(): self
    {
        return new self(
            'The Laravel application has no console kernel binding, so LaravelPlugin cannot '
            . 'bootstrap it. Create the application with Application::configure(...)->create(), '
            . 'which registers the kernel bindings.',
        );
    }

    public static function unknownServiceId(string $id, string $type): self
    {
        return new self(\sprintf(
            'The Laravel container has no binding "%s", requested for a parameter of type "%s". '
            . 'Check the id for typos, or bind the service in a service provider.',
            $id,
            $type,
        ));
    }

    public static function serviceTypeMismatch(string $id, string $type, string $actual): self
    {
        return new self(\sprintf(
            'The Laravel service "%s" is an instance of "%s", but the parameter declares "%s".',
            $id,
            $actual,
            $type,
        ));
    }
}
