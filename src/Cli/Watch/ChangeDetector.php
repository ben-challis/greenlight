<?php

declare(strict_types=1);

namespace Greenlight\Cli\Watch;

/** @internal */
interface ChangeDetector
{
    /**
     * @return list<FileChange> files changed since the previous poll
     */
    public function poll(): array;
}
