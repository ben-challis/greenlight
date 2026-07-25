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
}
