<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Harness;

use Greenlight\Attribute\DataSet;
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
    #[DataSet('nonObjectFactoryValues')]
    public function anImmediateFactoryReportsANonObjectValue(mixed $value, string $type): void
    {
        $factory = static fn(): mixed => $value;
        /** @var \Closure(): \Countable $factory */
        $scopes = new HarnessScopes(new HarnessRegistry([
            new ServiceDefinition(
                \Countable::class,
                Scope::PerRun,
                $factory,
            ),
        ]));

        Expect::that(static fn(): object => $scopes->resolve(\Countable::class, 'probe'))
            ->because('a harness factory contract error MUST identify a non-object value')
            ->toThrow(
                UnresolvableService::class,
                message: \sprintf(
                    'Service definition for type "Countable" created "%s". '
                    . 'Its factory MUST return an instance of "Countable".',
                    $type,
                ),
            );
    }

    /**
     * @return iterable<string, array{mixed, non-empty-string}>
     */
    public static function nonObjectFactoryValues(): iterable
    {
        yield 'string' => ['wrong type', 'string'];
        yield 'null' => [null, 'null'];
    }

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
