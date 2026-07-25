<?php

declare(strict_types=1);

namespace Greenlight\Doubles;

/** @internal */
enum DoubleKind
{
    case Mock;
    case Stub;
    case Spy;
}
