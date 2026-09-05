<?php

declare(strict_types=1);

namespace Greenlight\Amp;

/**
 * Identifies the attempt and child-work boundary for one managed Fiber.
 *
 * @internal
 */
final readonly class AmpScope
{
    public function __construct(
        public AmpAttempt $attempt,
        public bool $child,
    ) {}
}
