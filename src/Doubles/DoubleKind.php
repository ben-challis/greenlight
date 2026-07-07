<?php

declare(strict_types=1);

namespace Greenlight\Doubles;

/**
 * Behavioural flavour of a double.
 *
 * Mocks enforce their plan, stubs answer from it loosely, spies only record.
 *
 * @internal
 */
enum DoubleKind
{
    case Mock;
    case Stub;
    case Spy;
}
