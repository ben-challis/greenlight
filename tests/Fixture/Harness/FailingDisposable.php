<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\Harness;

use Greenlight\Doubles\Fake;
use Greenlight\Harness\Disposable;

final class FailingDisposable implements Disposable, Fake
{
    private static int $disposals = 0;

    public function initialize(): void {}

    #[\Override]
    public function dispose(): void
    {
        ++self::$disposals;

        throw new \RuntimeException('disposal broke');
    }

    public static function reset(): void
    {
        self::$disposals = 0;
    }

    public static function disposals(): int
    {
        return self::$disposals;
    }
}
