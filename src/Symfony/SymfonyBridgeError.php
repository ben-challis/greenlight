<?php

declare(strict_types=1);

namespace Greenlight\Symfony;

/** @internal */
final class SymfonyBridgeError extends \RuntimeException
{
    private function __construct(string $message)
    {
        parent::__construct($message);
    }

    public static function testContainerUnavailable(string $environment): self
    {
        return new self(\sprintf(
            'The kernel booted in the "%s" environment without Symfony\'s test container, '
            . 'so container services cannot reach tests. Enable framework.test for this '
            . 'environment (framework-bundle\'s standard test config, usually APP_ENV=test), '
            . 'or point SymfonyPlugin at an environment that has it.',
            $environment,
        ));
    }

    public static function resetterUnavailable(string $environment): self
    {
        return new self(\sprintf(
            'The kernel booted in the "%s" environment without services_resetter, so state '
            . 'cannot be reset between tests and the kernel-per-worker strategy would run '
            . 'unisolated. Enable framework-bundle\'s test configuration so services_resetter '
            . 'exists, or pass resetBetweenTests: false to SymfonyPlugin to deliberately waive '
            . 'resets (unsafe with any stateful service).',
            $environment,
        ));
    }

    public static function unknownServiceId(string $id, string $type): self
    {
        return new self(\sprintf(
            'The Symfony container has no service "%s", requested for a parameter of type "%s". '
            . 'Check the id for typos; private services are reachable through the test '
            . 'container, so an unknown id means the compiled container never had it.',
            $id,
            $type,
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
