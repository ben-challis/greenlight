<?php

declare(strict_types=1);

namespace Greenlight\Internal\Wire;

/**
 * Reports that a value or frame could not cross the internal worker wire.
 *
 * @internal
 */
abstract class WireCommunicationFailed extends \RuntimeException {}
