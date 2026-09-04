<?php

declare(strict_types=1);

namespace Greenlight\Expect;

/**
 * An extension matcher name conflicts with a public native expectation method.
 *
 * @internal
 */
final class ExpectationExtensionError extends \InvalidArgumentException
{
    public static function nativeMethod(string $name): self
    {
        return new self(\sprintf(
            'Extension matcher "%s" conflicts with a native expectation method. Rename the extension matcher.',
            $name,
        ));
    }
}
