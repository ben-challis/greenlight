<?php

declare(strict_types=1);

namespace Greenlight\Config;

/**
 * Raised when configuration values are structurally invalid: bad memory
 * strings, non-positive worker counts, empty suite names, and similar.
 *
 * @internal
 */
final class InvalidConfiguration extends \InvalidArgumentException {}
