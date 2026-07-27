<?php

declare(strict_types=1);

namespace Greenlight\Harness;

/**
 * A harness service that Greenlight disposes when its service scope closes.
 *
 * Greenlight disposes services in reverse creation order. An exception from
 * one service does not prevent disposal of the remaining services.
 */
interface Disposable
{
    public function dispose(): void;
}
