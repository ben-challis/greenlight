<?php

declare(strict_types=1);

namespace Greenlight\Plugin;

/** @internal */
enum CommandOutcome
{
    case Success;
    case Failure;
    case UsageError;
    case Interrupted;
}
