<?php

declare(strict_types=1);

namespace Greenlight\Config;

/**
 * Resolved watch-mode settings.
 *
 * @internal
 */
final readonly class WatchConfiguration
{
    /**
     * @param positive-int $debounceMilliseconds
     */
    public function __construct(
        public int $debounceMilliseconds = 200,
    ) {}
}
