<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\Runner\Worker;

final class CachedDataSetProbe
{
    public static int $providerCalls = 0;

    public function accepts(): never
    {
        throw new \LogicException('The cached data-set probe MUST NOT run.');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function rows(): iterable
    {
        ++self::$providerCalls;

        yield 'first' => ['alpha'];
        yield 'second' => ['beta'];
    }
}
