<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\Coverage;

use Greenlight\Doubles\Fake;

final class FailingPcovRuntime implements Fake
{
    /**
     * @var list<string>
     */
    public static array $calls = [];

    public static ?\Throwable $collectFailure = null;

    public static ?\Throwable $stopFailure = null;

    public static ?\Throwable $clearFailure = null;

    public static function reset(): void
    {
        self::$calls = [];
        self::$collectFailure = null;
        self::$stopFailure = null;
        self::$clearFailure = null;
    }

    public static function start(): void
    {
        self::$calls[] = 'start';
    }

    /**
     * @return array<string, array<int, int>>
     */
    public static function collect(): array
    {
        self::$calls[] = 'collect';

        if (self::$collectFailure instanceof \Throwable) {
            throw self::$collectFailure;
        }

        return ['/src/Example.php' => [10 => 1]];
    }

    public static function stop(): void
    {
        self::$calls[] = 'stop';

        if (self::$stopFailure instanceof \Throwable) {
            throw self::$stopFailure;
        }
    }

    public static function clear(): void
    {
        self::$calls[] = 'clear';

        if (self::$clearFailure instanceof \Throwable) {
            throw self::$clearFailure;
        }
    }
}
