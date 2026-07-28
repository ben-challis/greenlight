<?php

declare(strict_types=1);

namespace Greenlight\Core\Test;

/**
 * A live worker owns a unique channel from 1 to the worker count. A
 * replacement worker reuses a free channel. Thus, channel resources remain
 * available when Greenlight replaces a worker.
 * In-process runs use channel 1.
 *
 * `GREENLIGHT_CHANNEL` supplies the same value outside the harness. `label()`
 * adds "gl-" before the number.
 */
final readonly class TestChannel
{
    /**
     * @var positive-int
     */
    public int $number;

    /**
     * @throws \InvalidArgumentException
     */
    public function __construct(int $number)
    {
        if ($number < 1) {
            throw new \InvalidArgumentException('Test channel number MUST be greater than zero.');
        }

        $this->number = $number;
    }

    /**
     * @return non-empty-string
     */
    public function label(): string
    {
        return 'gl-' . $this->number;
    }
}
