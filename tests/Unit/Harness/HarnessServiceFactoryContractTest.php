<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Harness;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Harness\HarnessRegistry;
use Greenlight\Harness\HarnessScopes;
use Greenlight\Harness\Scope;
use Greenlight\Harness\ServiceDefinition;
use Greenlight\Harness\UnresolvableService;
use Greenlight\Tests\Fixture\Harness\FactoryContractTarget;

final class HarnessServiceFactoryContractTest
{
    #[Test]
    public function anImmediateFactoryMustReturnTheRegisteredType(): void
    {
        $scopes = $this->scopesFor(\Countable::class);

        Expect::that(static fn(): object => $scopes->resolve(\Countable::class, 'probe'))
            ->because('an immediate harness factory MUST return its registered type')
            ->toThrow(
                UnresolvableService::class,
                message: 'Service definition for type "Countable" created "stdClass". '
                . 'Its factory MUST return an instance of "Countable".',
            );
    }

    #[Test]
    public function aLazyFactoryMustReturnTheRegisteredTypeWhenInitialized(): void
    {
        $scopes = $this->scopesFor(FactoryContractTarget::class);
        $service = $scopes->resolve(FactoryContractTarget::class, 'probe');
        \assert($service instanceof FactoryContractTarget);

        Expect::that(static fn(): string => $service->value())
            ->because('a lazy harness factory MUST return its registered type when initialized')
            ->toThrow(
                UnresolvableService::class,
                message: 'Service definition for type "Greenlight\\Tests\\Fixture\\Harness\\FactoryContractTarget" '
                . 'created "stdClass". Its factory MUST return an instance of '
                . '"Greenlight\\Tests\\Fixture\\Harness\\FactoryContractTarget".',
            );
    }

    /**
     * @param class-string $type
     */
    private function scopesFor(string $type): HarnessScopes
    {
        return new HarnessScopes(new HarnessRegistry([
            new ServiceDefinition(
                $type,
                Scope::PerRun,
                static fn(): object => new \stdClass(),
            ),
        ]));
    }
}
