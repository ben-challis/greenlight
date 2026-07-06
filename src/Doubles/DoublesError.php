<?php

declare(strict_types=1);

namespace Greenlight\Doubles;

/**
 * A misuse of the doubles API: doubling a type outside the supported
 * boundary, planning a method that cannot be intercepted, or calling a
 * method whose return type has no derivable default. These are authoring
 * errors, not expectation failures, so the test errors rather than fails.
 *
 * @internal
 */
final class DoublesError extends \LogicException {}
