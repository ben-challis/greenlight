<?php

declare(strict_types=1);

namespace Greenlight\Attribute;

/**
 * Runs the test method, or every test in the class, in a dedicated fresh worker.
 */
#[\Attribute(\Attribute::TARGET_METHOD | \Attribute::TARGET_CLASS)]
final readonly class Isolated {}
