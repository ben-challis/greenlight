<?php

declare(strict_types=1);

namespace Greenlight\Harness;

/**
 * Defines the lifetime of a harness service.
 *
 * PerRun matches the worker lifetime.
 */
enum Scope: string
{
    case PerTest = 'per-test';
    case PerClass = 'per-class';
    case PerSuite = 'per-suite';
    case PerRun = 'per-run';
}
