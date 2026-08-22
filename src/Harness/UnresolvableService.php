<?php

declare(strict_types=1);

namespace Greenlight\Harness;

/**
 * Reports that constructor injection cannot resolve a parameter. This happens
 * if no harness service or service resolver supplies the required type. It
 * also happens if a resolver supplies the wrong type or the parameter has no
 * resolvable type.
 *
 * @internal
 */
final class UnresolvableService extends ServiceResolutionFailed
{
    private function __construct(string $message)
    {
        parent::__construct($message);
    }

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

    public static function factoryTypeMismatch(string $type, string $actual): self
    {
        return new self(\sprintf(
            'Service definition for type "%s" created "%s". Its factory MUST return an instance of "%s".',
            $type,
            $actual,
            $type,
        ));
    }

    public static function perClassServiceInParallelClass(string $type, string $consumer): self
    {
        return new self(\sprintf(
            'Per-class harness service "%s", required by "%s", cannot be used by a class with #[AllowParallel]. '
            . 'Use a per-test service or remove #[AllowParallel].',
            $type,
            $consumer,
        ));
    }

    public static function unsupportedParameter(string $parameter, string $consumer): self
    {
        return new self(\sprintf(
            'Constructor parameter $%s of "%s" has no resolvable type. '
            . 'A test constructor can declare only harness service types.',
            $parameter,
            $consumer,
        ));
    }
}
