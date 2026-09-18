<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Hyperf;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Hyperf\UnavailableContainer;
use Greenlight\Hyperf\UnavailableContainerError;
use Psr\Container\ContainerExceptionInterface;

final class UnavailableContainerTest
{
    #[Test]
    public function rejectsServiceAccessOutsideATestAttempt(): void
    {
        $container = new UnavailableContainer();

        Expect::value($container->has('clock'))->toBeFalse();
        Expect::calling(static fn(): never => $container->get('clock'))->toThrow(
            static function (UnavailableContainerError $error): void {
                Expect::value($error)->toBeInstanceOf(ContainerExceptionInterface::class);
                Expect::value($error->getMessage())->toBe(
                    'The Hyperf container is not active. Resolve Hyperf services only during a Greenlight test attempt.',
                );
                Expect::value($error->getCode())->toBe(0);
                Expect::value($error->getPrevious())->toBeNull();
            },
        );
    }
}
