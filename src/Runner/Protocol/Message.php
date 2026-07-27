<?php

declare(strict_types=1);

namespace Greenlight\Runner\Protocol;

use Greenlight\Core\Wire\WireSerializable;

/**
 * Each message contains a stable short type tag. Class names do not occur on
 * the wire.
 *
 * @internal
 */
interface Message extends WireSerializable
{
    /**
     * @return non-empty-string
     */
    public static function tag(): string;
}
