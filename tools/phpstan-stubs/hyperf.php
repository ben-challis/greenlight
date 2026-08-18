<?php

declare(strict_types=1);

namespace Hyperf\Context;

use Psr\Container\ContainerInterface;

class ApplicationContext
{
    /** @phpstan-impure */
    public static function setContainer(ContainerInterface $container): ContainerInterface
    {
        return $container;
    }
}

namespace Hyperf\Contract;

interface ApplicationInterface {}

namespace Hyperf\Coordinator;

class Constants
{
    public const string WORKER_EXIT = 'WORKER_EXIT';
}

class Coordinator
{
    /** @phpstan-impure */
    public function resume(mixed $value = null): void {}
}

class CoordinatorManager
{
    public static function until(string $identifier): Coordinator
    {
        return new Coordinator();
    }
}

namespace Hyperf\Di;

class ClassLoader
{
    /** @phpstan-impure */
    public static function init(): void {}
}

namespace Hyperf\Coroutine;

class Coroutine
{
    public static function inCoroutine(): bool
    {
        return true;
    }
}

/**
 * @param callable(): void $callbacks
 * @phpstan-impure
 */
function run(callable $callbacks, int $flags): bool
{
    $callbacks();

    return true;
}

namespace Swoole;

class Coroutine
{
    /**
     * @param callable(): void $callback
     * @phpstan-impure
     */
    public static function create(callable $callback): int|false
    {
        $callback();

        return 1;
    }
}

class Timer
{
    /** @phpstan-impure */
    public static function clearAll(): bool
    {
        return true;
    }
}

class Runtime
{
    /** @phpstan-impure */
    public static function enableCoroutine(int $flags = 0): bool
    {
        return true;
    }
}

namespace Swoole\Coroutine;

class Channel
{
    private mixed $value = null;

    public function __construct(private readonly int $capacity = 1) {}

    /** @phpstan-impure */
    public function push(mixed $value): bool
    {
        if ($this->capacity < 1) {
            return false;
        }

        $this->value = $value;

        return true;
    }

    /** @phpstan-impure */
    public function pop(float $timeout = -1): mixed
    {
        return $this->value;
    }

    /** @phpstan-impure */
    public function close(): bool
    {
        return true;
    }
}
