<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Bridge;

use Greenlight\Attribute\Test;
use Greenlight\Bridge\ContainerServiceResolver;
use Greenlight\Harness\Service;
use Greenlight\Harness\ServiceResolutionFailed;
use Greenlight\Hyperf\HyperfBridgeError;
use Greenlight\Psr11\Psr11BridgeError;

use function Greenlight\expect;

final readonly class ContainerServiceResolverTest
{
    #[Test]
    public function aMissingServiceWithoutAnAttributeReturnsNull(): void
    {
        expect($this->resolve([], []))->toBeNull();
    }

    #[Test]
    public function aMissingServiceWithAnAttributeFails(): void
    {
        expect()->calling(fn(): ?object => $this->resolve([], [new Service()]))
            ->toThrow(Psr11BridgeError::class, message: 'The PSR-11 container has no service "ArrayObject" for the parameter of type "ArrayObject". Check the service ID.');
    }

    #[Test]
    public function theLastServiceAttributeSelectsTheId(): void
    {
        $service = new \ArrayObject();

        expect($this->resolve(['second' => $service], [new Service('first'), new Service('second')]))->toBe($service);
    }

    #[Test]
    public function aServiceOfAnotherTypeFails(): void
    {
        expect()->calling(fn(): ?object => $this->resolve([\ArrayObject::class => new \stdClass()], []))
            ->toThrow(Psr11BridgeError::class, message: 'PSR-11 service "ArrayObject" has type "stdClass". The parameter requires type "ArrayObject".');
    }

    #[Test]
    public function aFailedCheckUsesTheCheckError(): void
    {
        $cause = new \RuntimeException('The check failed.');

        expect()->calling(static fn(): ?object => ContainerServiceResolver::resolve(
            \ArrayObject::class,
            [],
            static fn(string $id): never => throw $cause,
            static fn(string $id): mixed => null,
            Psr11BridgeError::class,
        ))->toThrow(Psr11BridgeError::class, message: 'The PSR-11 container failed when it checked service "ArrayObject". Check the container configuration.');
    }

    #[Test]
    public function aFailedReadUsesTheReadError(): void
    {
        $cause = new \RuntimeException('The read failed.');
        $caught = null;

        try {
            ContainerServiceResolver::resolve(
                \ArrayObject::class,
                [],
                static fn(string $id): bool => true,
                static fn(string $id): never => throw $cause,
                Psr11BridgeError::class,
            );
        } catch (Psr11BridgeError $error) {
            $caught = $error;
        }

        expect($caught?->getMessage())->toBe('The PSR-11 container failed when it read service "ArrayObject". Check the container configuration.');
        expect($caught?->getPrevious())->toBe($cause);
    }

    #[Test]
    public function aBridgeWithOneFailureMessageUsesItForBothCalls(): void
    {
        $message = 'The Hyperf container failed when it resolved service "ArrayObject" for parameter type "ArrayObject".';

        expect()->calling(static fn(): ?object => ContainerServiceResolver::resolve(
            \ArrayObject::class,
            [],
            static fn(string $id): never => throw new \RuntimeException('The check failed.'),
            static fn(string $id): mixed => null,
            HyperfBridgeError::class,
        ))->toThrow(HyperfBridgeError::class, message: $message);
        expect()->calling(static fn(): ?object => ContainerServiceResolver::resolve(
            \ArrayObject::class,
            [],
            static fn(string $id): bool => true,
            static fn(string $id): never => throw new \RuntimeException('The read failed.'),
            HyperfBridgeError::class,
        ))->toThrow(HyperfBridgeError::class, message: $message);
    }

    #[Test]
    public function aResolutionFailureFromTheContainerPassesThrough(): void
    {
        $failure = HyperfBridgeError::containerOutsideAttempt();

        expect()->calling(static fn(): ?object => ContainerServiceResolver::resolve(
            \ArrayObject::class,
            [],
            static fn(string $id): never => throw $failure,
            static fn(string $id): mixed => null,
            Psr11BridgeError::class,
        ))->toThrow($failure);
        expect()->calling(static fn(): ?object => ContainerServiceResolver::resolve(
            \ArrayObject::class,
            [],
            static fn(string $id): bool => true,
            static fn(string $id): never => throw $failure,
            Psr11BridgeError::class,
        ))->toThrow($failure);
    }

    /**
     * @param array<string, mixed> $services
     * @param list<object> $attributes
     * @throws ServiceResolutionFailed
     */
    private function resolve(array $services, array $attributes): ?object
    {
        return ContainerServiceResolver::resolve(
            \ArrayObject::class,
            $attributes,
            static fn(string $id): bool => \array_key_exists($id, $services),
            static fn(string $id): mixed => $services[$id],
            Psr11BridgeError::class,
        );
    }
}
