<?php

declare(strict_types=1);

namespace Greenlight\Cli\Watch;

/** @internal */
interface KeyInput
{
    /**
     * @return non-empty-string|null the next pressed key, or null when none is pending
     */
    public function poll(): ?string;

    /**
     * Discards all pending keys.
     *
     * @return bool True when q was one of the discarded keys.
     */
    public function discardPending(): bool;
}
