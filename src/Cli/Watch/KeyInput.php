<?php

declare(strict_types=1);

namespace Greenlight\Cli\Watch;

/**
 * Non-blocking keyboard input for the watch loop. Injectable for tests.
 *
 * @internal
 */
interface KeyInput
{
    /**
     * @return non-empty-string|null the next pressed key, or null when none is pending
     */
    public function poll(): ?string;
}
