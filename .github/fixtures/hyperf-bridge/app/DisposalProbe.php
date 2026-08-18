<?php

declare(strict_types=1);

namespace App;

use Hyperf\Coroutine\Coroutine;

final class DisposalProbe
{
    public static int $containers = 0;

    public static int $resets = 0;

    public static int $disposals = 0;

    public static bool $resetInCoroutine = false;

    public static bool $disposeInCoroutine = false;

    public static function containerCreated(): void
    {
        ++self::$containers;
    }

    public function reset(): void
    {
        ++self::$resets;
        self::$resetInCoroutine = Coroutine::inCoroutine();
    }

    public function dispose(): void
    {
        ++self::$disposals;
        self::$disposeInCoroutine = Coroutine::inCoroutine();
    }

    /** @return array{containers: int, resets: int, disposals: int, resetInCoroutine: bool, disposeInCoroutine: bool} */
    public function snapshot(): array
    {
        return [
            'containers' => self::$containers,
            'resets' => self::$resets,
            'disposals' => self::$disposals,
            'resetInCoroutine' => self::$resetInCoroutine,
            'disposeInCoroutine' => self::$disposeInCoroutine,
        ];
    }
}
