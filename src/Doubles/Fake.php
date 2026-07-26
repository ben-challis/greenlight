<?php

declare(strict_types=1);

namespace Greenlight\Doubles;

/**
 * Identifies a manual in-memory test implementation.
 *
 * The interface does not change behavior. It lets reporters and tools
 * identify the object as an intentional fake, not production code under
 * test.
 */
interface Fake {}
