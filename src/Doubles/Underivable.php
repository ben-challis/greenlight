<?php

declare(strict_types=1);

namespace Greenlight\Doubles;

/**
 * Sentinel returned by DefaultValues when a return type has no derivable
 * default, distinguishing "default is null" from "no default exists".
 *
 * @internal
 */
final readonly class Underivable
{
    public function __construct(
        public string $reason,
    ) {}
}
