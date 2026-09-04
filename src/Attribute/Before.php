<?php

declare(strict_types=1);

namespace Greenlight\Attribute;

/** Runs the method before each test attempt in the class. */
#[\Attribute(\Attribute::TARGET_METHOD)]
final readonly class Before {}
