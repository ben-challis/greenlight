<?php

declare(strict_types=1);

namespace Greenlight\Cli\Watch;

/** @internal */
interface ChangeDetector
{
    /**
     * @return list<non-empty-string> paths changed since the previous poll
     */
    public function poll(): array;
}
