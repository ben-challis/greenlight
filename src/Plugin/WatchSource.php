<?php

declare(strict_types=1);

namespace Greenlight\Plugin;

/** Reports changes that cause a watch-mode rerun. */
interface WatchSource extends Plugin
{
    /**
     * @return list<non-empty-string> changed paths or trigger labels since the previous poll
     */
    public function poll(): array;
}
