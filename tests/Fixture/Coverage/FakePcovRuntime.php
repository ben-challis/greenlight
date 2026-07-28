<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\Coverage;

use Greenlight\Doubles\Fake;

final class FakePcovRuntime implements Fake
{
    /**
     * @var list<string>
     */
    public static array $calls = [];

    public static function start(): void
    {
        self::$calls[] = 'start';
    }

    /**
     * @return array<mixed>
     */
    public static function collect(): array
    {
        self::$calls[] = 'collect';

        return [
            '/src/Example.php' => [
                10 => 1,
                11 => -1,
                'invalid-line' => 1,
                12 => 'invalid-status',
            ],
            7 => [1 => 1],
            '/src/invalid.php' => 'invalid-lines',
        ];
    }

    public static function stop(): void
    {
        self::$calls[] = 'stop';
    }

    public static function clear(): void
    {
        self::$calls[] = 'clear';
    }
}
