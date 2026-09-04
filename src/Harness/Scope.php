<?php

declare(strict_types=1);

namespace Greenlight\Harness;

/**
 * Defines the lifetime of a harness service.
 *
 * `PerWorker` matches the physical worker lifetime.
 */
enum Scope: string
{
    case PerTest = 'per-test';
    case PerClass = 'per-class';
    case PerWorker = 'per-worker';
}
