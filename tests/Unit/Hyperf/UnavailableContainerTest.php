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

        Expect::that($container->has('clock'))->toBeFalse();
        Expect::that(static fn(): never => $container->get('clock'))->toThrow(
            static function (UnavailableContainerError $error): void {
                Expect::that($error)->toBeInstanceOf(ContainerExceptionInterface::class);
                Expect::that($error->getMessage())->toBe(
                    'The Hyperf container is not active. Resolve Hyperf services only during a Greenlight test attempt.',
                );
                Expect::that($error->getCode())->toBe(0);
                Expect::that($error->getPrevious())->toBeNull();
            },
        );
    }

    #[Test]
    public function errorsRequireANamedFactory(): void
    {
        Expect::that(static fn(): object => new \ReflectionClass(UnavailableContainerError::class)->newInstance())
            ->toThrow(\ReflectionException::class);
    }
}
