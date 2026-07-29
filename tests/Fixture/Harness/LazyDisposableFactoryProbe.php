<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\Harness;

use Greenlight\Doubles\Fake;
use Greenlight\Harness\Disposable;

final class LazyDisposableFactoryProbe implements Disposable, Fake
{
    private static int $disposals = 0;

    public function __construct(private string $value) {}

    public function value(): string
    {
        return $this->value;
    }

    #[\Override]
    public function dispose(): void
    {
        if ($this->value !== '') {
            ++self::$disposals;
        }
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
