<?php

declare(strict_types=1);

namespace Greenlight\Harness;

/**
 * Raised when constructor injection cannot resolve a parameter: no harness
 * service is registered for the type and no fallback resolver supplied it,
 * a resolver answered with the wrong type, or the parameter has no
 * resolvable type at all.
 *
 * @internal
 */
final class UnresolvableService extends \RuntimeException
{
    public static function unknownType(string $type, string $consumer, int $resolversConsulted = 0): self
    {
        $suffix = $resolversConsulted === 0
            ? 'Constructor injection resolves exact types only.'
            : \sprintf(
                'Constructor injection resolves exact types only, and none of the %d fallback resolver(s) supplied it.',
                $resolversConsulted,
            );

        return new self(\sprintf(
            'No harness service is registered for type "%s", required by "%s". %s',
            $type,
            $consumer,
            $suffix,
        ));
    }

    public static function resolverTypeMismatch(string $type, string $consumer, string $resolver, string $actual): self
    {
        return new self(\sprintf(
            'Resolver "%s" answered the request for "%s" (required by "%s") with an instance of "%s", '
            . 'which is not that type.',
            $resolver,
            $type,
            $consumer,
            $actual,
        ));
    }

    public static function unsupportedParameter(string $parameter, string $consumer): self
    {
        return new self(\sprintf(
            'Constructor parameter $%s of "%s" has no resolvable type. '
            . 'Test constructors may only declare harness service types.',
            $parameter,
            $consumer,
        ));
    }
}
