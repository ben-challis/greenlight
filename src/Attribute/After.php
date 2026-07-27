<?php

declare(strict_types=1);

namespace Greenlight\Attribute;

/**
 * Runs at the end of an attempt that has a test instance. It also runs when
 * setup or the test method does not complete.
 */
#[\Attribute(\Attribute::TARGET_METHOD)]
final readonly class After {}
