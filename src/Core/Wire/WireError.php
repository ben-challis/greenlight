<?php

declare(strict_types=1);

namespace Greenlight\Core\Wire;

/**
 * A value or frame cannot cross the orchestrator-worker wire boundary.
 */
abstract class WireError extends \RuntimeException {}
