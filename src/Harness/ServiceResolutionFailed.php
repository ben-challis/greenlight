<?php

declare(strict_types=1);

namespace Greenlight\Harness;

/**
 * Greenlight could not supply a valid service from a harness or container integration.
 */
abstract class ServiceResolutionFailed extends \RuntimeException {}
