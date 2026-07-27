<?php

declare(strict_types=1);

namespace Greenlight\Cli;

/**
 * Identifies if an option takes no value, an optional value, or a required value.
 *
 * Examples are --help, --bail or --bail=3, and --workers=4.
 *
 * @internal
 */
enum OptionValue
{
    case None;
    case Optional;
    case Required;
}
