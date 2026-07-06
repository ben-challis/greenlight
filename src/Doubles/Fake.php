<?php

declare(strict_types=1);

namespace Greenlight\Doubles;

/**
 * Marker for hand-written in-memory test implementations. Implementing it
 * changes no behaviour; it lets reporters and tooling label the object as a
 * deliberate fake rather than production code under test.
 */
interface Fake {}
