<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Harness;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Harness\HarnessRegistry;
use Greenlight\Harness\HarnessScopes;
use Greenlight\Harness\Scope;
use Greenlight\Harness\ServiceDefinition;

final readonly class HarnessRegistryTypeCaseTest
{
    #[Test]
    public function serviceTypeNamesFollowPhpCaseInsensitivity(): void
    {
        $service = new \ArrayObject();
        $registry = new HarnessRegistry([
            new ServiceDefinition(
                $this->uppercaseClassName(\ArrayObject::class),
                Scope::PerWorker,
                static fn(): \ArrayObject => $service,
            ),
        ]);

        Expect::that(new HarnessScopes($registry)->resolve(\ArrayObject::class, self::class))
            ->because('harness service identity MUST follow PHP type-name identity')
            ->toBe($service);
    }

    #[Test]
    public function caseOnlyDuplicateServiceTypesAreRejected(): void
    {
        $registry = new HarnessRegistry([
            new ServiceDefinition(
                \ArrayObject::class,
                Scope::PerWorker,
                static fn(): \ArrayObject => new \ArrayObject(),
            ),
        ]);
        $duplicate = new ServiceDefinition(
            $this->uppercaseClassName(\ArrayObject::class),
            Scope::PerTest,
            static fn(): \ArrayObject => new \ArrayObject(),
        );

        Expect::that(static function () use ($duplicate, $registry): void {
            $registry->register($duplicate);
        })
            ->because('one PHP type MUST have only one harness service definition')
            ->toThrow(
                \LogicException::class,
                message: 'A harness service for ARRAYOBJECT is already registered.',
            );
    }

    /**
     * @param class-string $type
     *
     * @return class-string
     */
    private function uppercaseClassName(string $type): string
    {
        $uppercase = \strtoupper($type);

        if (!\class_exists($uppercase)) {
            throw new \LogicException(\sprintf('Class %s is unavailable.', $uppercase));
        }

        return $uppercase;
    }
}
