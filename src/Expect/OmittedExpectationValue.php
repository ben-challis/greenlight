<?php

declare(strict_types=1);

namespace Greenlight\Expect;

/**
 * Distinguishes an omitted helper argument from an explicit null value.
 *
 * @internal
 */
enum OmittedExpectationValue
{
    case Value;
}
