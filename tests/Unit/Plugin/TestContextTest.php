<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Plugin;

use Greenlight\Attribute\Test;
use Greenlight\Core\Test\TestId;
use Greenlight\Core\Test\TestMetadata;
use Greenlight\Expect\Expect;
use Greenlight\Harness\HarnessRegistry;
use Greenlight\Harness\HarnessScopes;
use Greenlight\Harness\Scope;
use Greenlight\Harness\ServiceDefinition;
use Greenlight\Harness\UnresolvableService;
use Greenlight\Plugin\TestContext;

final class TestContextTest
{
    #[Test]
    public function serviceResolvesARegisteredHarnessService(): void
    {
        $registry = new HarnessRegistry([
            new ServiceDefinition(
                \ArrayObject::class,
                Scope::PerRun,
                static fn(): \ArrayObject => new \ArrayObject(['ready']),
            ),
        ]);
        $context = $this->context(new HarnessScopes($registry));

        $service = $context->service(\ArrayObject::class);

        Expect::that($service)
            ->because('the plugin context resolves a registered harness service')
            ->toBeInstanceOf(\ArrayObject::class)
            ->and($service->getArrayCopy())->toBe(['ready']);
    }

    #[Test]
    public function missingServiceNamesThePluginContext(): void
    {
        $context = $this->context(new HarnessScopes(new HarnessRegistry()));

        Expect::that(static fn(): object => $context->service(\ArrayObject::class))
            ->because('a missing service identifies the plugin context')
            ->toThrow(
                UnresolvableService::class,
                message: 'No harness service is registered for type "ArrayObject", required by "plugin context for Fixture\\PluginTest". '
                . 'Constructor injection resolves exact types only.',
            );
    }

    private function context(HarnessScopes $scopes): TestContext
    {
        return new TestContext(
            new \stdClass(),
            new TestId('Fixture\\PluginTest', 'probe'),
            new TestMetadata('Fixture\\PluginTest', 'probe'),
            $scopes,
        );
    }
}
