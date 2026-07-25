<?php

declare(strict_types=1);

namespace Greenlight\Core\Test;

/**
 * A live worker owns a unique slot from 1 to the worker count. Replacements
 * reuse freed slots, so channel resources persist across worker recycling.
 * In-process runs use channel 1.
 *
 * GREENLIGHT_CHANNEL exposes the same value outside the harness. label()
 * prefixes the raw number with "gl-".
 */
final readonly class TestChannel
{
    /**
     * @param positive-int $number
     */
    public function __construct(public int $number) {}

    /**
     * @return non-empty-string
     */
    public function label(): string
    {
        return 'gl-' . $this->number;
    }
}
