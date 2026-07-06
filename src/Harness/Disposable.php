<?php

declare(strict_types=1);

namespace Greenlight\Harness;

/**
 * A harness service that must be torn down when its scope closes. Disposal
 * runs in reverse creation order and is exception-safe: every disposable is
 * disposed even when an earlier one throws.
 */
interface Disposable
{
    public function dispose(): void;
}
