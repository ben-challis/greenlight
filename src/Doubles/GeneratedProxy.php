<?php

declare(strict_types=1);

namespace Greenlight\Doubles;

/**
 * Implemented by every generated proxy class.
 *
 * The obscure method name keeps the injected handler out of the way of the
 * doubled type's own surface.
 *
 * @internal
 */
interface GeneratedProxy
{
    public function __greenlightAttachHandler(CallHandler $handler): void;
}
