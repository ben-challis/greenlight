<?php

declare(strict_types=1);

namespace Greenlight\Attribute;

/** Runs after a test, even if the test fails. */
#[\Attribute(\Attribute::TARGET_METHOD)]
final readonly class After {}
