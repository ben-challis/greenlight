<?php

declare(strict_types=1);

namespace Greenlight\Harness;

/**
 * @internal
 */
final class UnresolvableService extends \RuntimeException
{
    public static function unknownType(string $type, string $consumer): self
    {
        return new self(\sprintf(
            'No harness service is registered for type %s, required by %s. '
            . 'Constructor injection resolves exact types only.',
            $type,
            $consumer,
        ));
    }

    public static function unsupportedParameter(string $parameter, string $consumer): self
    {
        return new self(\sprintf(
            'Constructor parameter $%s of %s has no resolvable type. '
            . 'Test constructors may only declare harness service types.',
            $parameter,
            $consumer,
        ));
    }
}
