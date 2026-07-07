<?php

declare(strict_types=1);

namespace Greenlight\Runner\Protocol;

use Greenlight\Core\Wire\WireSerializable;

/**
 * A protocol message.
 *
 * Each carries a stable short type tag; class names never appear on the
 * wire.
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
