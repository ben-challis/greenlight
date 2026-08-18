<?php

declare(strict_types=1);

namespace Greenlight\Hyperf;

use Psr\Container\ContainerExceptionInterface;

/**
 * Reports Hyperf container access outside a test attempt.
 *
 * @internal
 */
final class UnavailableContainerError extends \RuntimeException implements ContainerExceptionInterface
{
    public function __construct()
    {
        parent::__construct(
            'The Hyperf container is not active. Resolve Hyperf services only during a Greenlight test attempt.',
        );
    }
}
