<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\Execution\Worker\Attempt;

use Greenlight\Test\ExpectationCounter;

final class DataSetProbe
{
    public static int $slowCalls = 0;
    public static int $failingCalls = 0;

    public function accepts(string $value): void
    {
        ExpectationCounter::increment();
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function slowRows(): iterable
    {
        ++self::$slowCalls;
        \usleep(300_000);

        yield 'first' => ['alpha'];
        yield 'second' => ['beta'];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function failingRows(): iterable
    {
        ++self::$failingCalls;

        throw new \RuntimeException('provider failed');
    }
}
