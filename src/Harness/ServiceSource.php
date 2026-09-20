<?php

declare(strict_types=1);

namespace Greenlight\Harness;

/**
 * Names one source of harness definitions or resolved services. Source names
 * are case-sensitive. A null name keeps the source unnamed.
 */
interface ServiceSource
{
    /** @return non-empty-string|null */
    public function source(): ?string;
}
