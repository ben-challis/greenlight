<?php

declare(strict_types=1);

namespace Greenlight\Harness;

/**
 * Lifetime of a harness service. PerRun means per worker lifetime.
 */
enum Scope: string
{
    case PerTest = 'per-test';
    case PerClass = 'per-class';
    case PerSuite = 'per-suite';
    case PerRun = 'per-run';
}
