<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\CoverageIgnoreLib;

use Greenlight\Attribute\CoverageIgnore;

final class Gadget
{
    /**
     * @codeCoverageIgnore
     */
    private function __construct() {}

    public static function double(int $value): int
    {
        if ($value === \PHP_INT_MIN) {
            return 0; // @codeCoverageIgnore
        }

        // @codeCoverageIgnoreStart
        if ($value === \PHP_INT_MAX) {
            return -1;
        }
        // @codeCoverageIgnoreEnd

        return $value * 2;
    }

    #[CoverageIgnore]
    public static function debugDump(int $value): string
    {
        return \var_export($value, true);
    }
}
