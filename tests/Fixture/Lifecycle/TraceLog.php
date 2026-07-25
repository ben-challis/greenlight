<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\Lifecycle;

final class TraceLog
{
    /**
     * @var list<string>
     */
    private static array $entries = [];

    private function __construct() {}

    public static function add(string $entry): void
    {
        self::$entries[] = $entry;
    }

    /**
     * @return list<string>
     */
    public static function drain(): array
    {
        $entries = self::$entries;
        self::$entries = [];

        return $entries;
    }
}
