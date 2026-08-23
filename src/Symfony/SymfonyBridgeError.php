<?php

declare(strict_types=1);

namespace Greenlight\Symfony;

use Greenlight\Harness\ServiceResolutionFailed;

/**
 * Reports a Symfony bridge configuration or runtime failure.
 *
 * @internal
 */
final class SymfonyBridgeError extends ServiceResolutionFailed
{
    private function __construct(string $message, ?\Throwable $previous = null)
    {
        parent::__construct($message, previous: $previous);
    }

    public static function testContainerUnavailable(string $environment): self
    {
        return new self(\sprintf(
            'The kernel started in the "%s" environment without the Symfony test container. '
            . 'Tests cannot use container services. Enable framework.test for this environment. '
            . 'The standard FrameworkBundle test configuration usually uses APP_ENV=test. '
            . 'You can also configure SymfonyPlugin to use an environment that provides the test container.',
            $environment,
        ));
    }

    public static function resetterUnavailable(string $environment): self
    {
        return new self(\sprintf(
            'The kernel started in the "%s" environment without services_resetter. '
            . 'Symfony cannot reset service state between tests. Tests on the same worker can share state. '
            . 'Enable the FrameworkBundle test configuration. To accept this risk, pass resetBetweenTests: false to SymfonyPlugin. '
            . 'Use this option only if services do not keep state.',
            $environment,
        ));
    }

    public static function unknownServiceId(string $id, string $type): self
    {
        return new self(\sprintf(
            'The Symfony container has no service "%s" for the parameter of type "%s". '
            . 'Check the service ID. The test container exposes private services. '
            . 'The compiled container does not define the requested service.',
            $id,
            $type,
        ));
    }

    public static function serviceTypeMismatch(string $id, string $type, string $actual): self
    {
        return new self(\sprintf(
            'Symfony service "%s" has type "%s". The parameter requires type "%s".',
            $id,
            $actual,
            $type,
        ));
    }

    public static function notAKernel(string $class): self
    {
        return new self(\sprintf(
            'Class "%s" does not implement Symfony\Component\HttpKernel\KernelInterface. '
            . 'SymfonyPlugin cannot use this class as a kernel.',
            $class,
        ));
    }

    public static function notAKernelFromFactory(string $actual): self
    {
        return new self(\sprintf(
            'The Symfony kernel factory returned "%s". Return an instance of Symfony\Component\HttpKernel\KernelInterface.',
            $actual,
        ));
    }

    public static function serviceResolutionFailed(string $id, string $type, \Throwable $previous): self
    {
        return new self(
            \sprintf(
                'The Symfony container failed when it resolved service "%s" for parameter type "%s".',
                $id,
                $type,
            ),
            previous: $previous,
        );
    }
}
