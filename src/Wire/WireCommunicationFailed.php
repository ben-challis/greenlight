<?php

declare(strict_types=1);

namespace Greenlight\Wire;

/**
 * A value or frame could not cross the orchestrator-worker wire boundary.
 */
abstract class WireCommunicationFailed extends \RuntimeException {}
