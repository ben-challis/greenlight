<?php

declare(strict_types=1);

namespace Greenlight\Harness;

/**
 * A harness service resolver cannot supply a valid service.
 */
abstract class ServiceResolutionError extends \RuntimeException {}
