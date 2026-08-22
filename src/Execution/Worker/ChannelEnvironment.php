<?php

declare(strict_types=1);

namespace Greenlight\Execution\Worker;

use Greenlight\Internal\Text\DecimalInteger;

/**
 * Parses the worker channel from its environment value.
 *
 * @internal
 */
final class ChannelEnvironment
{
    /** @codeCoverageIgnore */
    private function __construct() {}

    /**
     * @return positive-int|null
     */
    public static function parse(string|false $raw): ?int
    {
        if (!\is_string($raw)) {
            return null;
        }

        $channel = DecimalInteger::parse($raw);

        return $channel !== null && $channel > 0 ? $channel : null;
    }
}
