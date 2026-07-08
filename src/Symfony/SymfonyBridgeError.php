<?php

declare(strict_types=1);

namespace Greenlight\Symfony;

/**
 * Raised when the Symfony bridge cannot serve a resolution it was explicitly
 * asked for.
 *
 * unknownServiceId() covers a #[Service] id the container does not expose,
 * with a hint towards framework.test when the test container is absent.
 * serviceTypeMismatch() covers a container service that is not an instance
 * of the declared parameter type. notAKernel() covers a plugin configured
 * with a class that does not implement KernelInterface.
 *
 * @internal
 */
final class SymfonyBridgeError extends \RuntimeException
{
    public static function unknownServiceId(string $id, string $type, bool $testContainerAvailable): self
    {
        $hint = $testContainerAvailable
            ? 'Check the id for typos; private services are reachable, so an unknown id means the container never had it.'
            : 'The test container is not available: boot the kernel with framework.test enabled '
            . '(usually APP_ENV=test) so private services become reachable.';

        return new self(\sprintf(
            'The Symfony container has no service "%s", requested for a parameter of type "%s". %s',
            $id,
            $type,
            $hint,
        ));
    }

    public static function serviceTypeMismatch(string $id, string $type, string $actual): self
    {
        return new self(\sprintf(
            'The Symfony service "%s" is an instance of "%s", but the parameter declares "%s".',
            $id,
            $actual,
            $type,
        ));
    }

    public static function notAKernel(string $class): self
    {
        return new self(\sprintf(
            '"%s" does not implement Symfony\Component\HttpKernel\KernelInterface, '
            . 'so SymfonyPlugin cannot boot it.',
            $class,
        ));
    }
}
