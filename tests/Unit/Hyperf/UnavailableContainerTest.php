<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Hyperf;

use Greenlight\Attribute\Test;
use Greenlight\Hyperf\UnavailableContainer;
use Greenlight\Hyperf\UnavailableContainerError;
use Psr\Container\ContainerExceptionInterface;

use function Greenlight\expect;

final class UnavailableContainerTest
{
    #[Test]
    public function rejectsServiceAccessOutsideATestAttempt(): void
    {
        $container = new UnavailableContainer();

        expect($container->has('clock'))->toBeFalse();
        expect()->calling(static fn(): never => $container->get('clock'))->toThrow(
            static function (UnavailableContainerError $error): void {
                expect($error)->toBeInstanceOf(ContainerExceptionInterface::class);
                expect($error->getMessage())->toBe(
                    'The Hyperf container is not active. Resolve Hyperf services only during a Greenlight test attempt.',
                );
                expect($error->getCode())->toBe(0);
                expect($error->getPrevious())->toBeNull();
            },
        );
    }
}
