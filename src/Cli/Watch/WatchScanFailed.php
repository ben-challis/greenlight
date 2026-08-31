<?php

declare(strict_types=1);

namespace Greenlight\Cli\Watch;

/**
 * Indicates that a watch poll matched more files than its configured limit.
 *
 * @internal
 */
final class WatchScanFailed extends \RuntimeException {}
