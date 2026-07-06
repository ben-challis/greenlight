<?php

declare(strict_types=1);

namespace Greenlight\Cli;

/**
 * Whether an option takes a value: never (--help), optionally (--bail or
 * --bail=3), or always (--workers=4).
 *
 * @internal
 */
enum OptionValue
{
    case None;
    case Optional;
    case Required;
}
