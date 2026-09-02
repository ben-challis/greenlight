<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Tools;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;

final readonly class ApiReferenceGenerationTest
{
    #[Test]
    public function privatePromotedParametersDoNotHidePublicConstructors(): void
    {
        Expect::that($this->reference('api-integrations.md'))
            ->because('private promoted parameters MUST NOT hide a public constructor')
            ->toContain(<<<'MARKDOWN'
                ### `__construct()`

                ```php
                public function __construct(
                    string|\Closure $application,
                    private readonly string $env = 'testing',
                    private readonly bool $refreshBetweenTests = true,
                )
                MARKDOWN);
    }

    #[Test]
    public function mixedVisibilityPromotionIncludesPublicProperties(): void
    {
        Expect::that($this->reference('api-plugins.md'))
            ->because('the API reference MUST include each public promoted property')
            ->toContain('### `$instance`')
            ->toContain('### `$id`')
            ->toContain('### `$definition`')
            ->not()->toContain('### `$scopes`');
    }

    #[Test]
    public function integrationFixtureTypesHaveAStableSection(): void
    {
        $reference = $this->reference('api-integration-fixtures.md');

        Expect::that($reference)
            ->because('the integration fixture API MUST contain each public fixture type')
            ->toContain('## `FixtureResource`')
            ->toContain('## `IntegrationResources`')
            ->toContain('## `SensitiveValue`')
            ->toContain('## `IntegrationFixtureContext`')
            ->toContain('## `IntegrationFixtureDefinition`');
        Expect::that($this->reference('api-harness.md'))
            ->because('the harness API MUST contain only harness types')
            ->not()->toContain('## `FixtureResource`')
            ->not()->toContain('## `IntegrationResources`')
            ->not()->toContain('## `SensitiveValue`');
        Expect::that($this->reference('api-plugins.md'))
            ->because('the plugin API MUST contain only plugin types')
            ->not()->toContain('## `IntegrationFixtureContext`')
            ->not()->toContain('## `IntegrationFixtureDefinition`');
    }

    #[Test]
    public function serviceResolutionUsesTheSharedHarnessContract(): void
    {
        $harness = $this->reference('api-harness.md');

        Expect::that($harness)
            ->because('the harness API MUST contain the shared service attribute')
            ->toContain('## `Service`')
            ->toContain('public function resolve(string $type, array $attributes): ?object;')
            ->not()->toContain('## `ServiceResolution`');
        Expect::that($this->reference('api-integrations.md'))
            ->because('integration APIs MUST NOT contain framework-specific service attributes')
            ->not()->toContain('## `Hyperf\\Service`')
            ->not()->toContain('## `Laravel\\Service`')
            ->not()->toContain('## `Psr11\\Service`')
            ->not()->toContain('## `Symfony\\Service`');
    }

    #[Test]
    public function temporalTypesShowTheirFluentInterfaceWithoutConstructionDetails(): void
    {
        $reference = $this->reference('api-expectations.md');

        Expect::that($reference)
            ->because('temporal types MUST document their fluent interface')
            ->toContain('## `TemporalExpectation`')
            ->toContain('## `EventuallyExpectation`')
            ->toContain('## `ConsistentlyExpectation`')
            ->toContain('class EventuallyExpectation extends TemporalExpectation')
            ->toContain('class ConsistentlyExpectation extends TemporalExpectation')
            ->toContain('### `not()`')
            ->toContain('### `because()`')
            ->toContain('### `toBe()`')
            ->toContain('Runs a native or configured extension matcher against each probe value.');
        Expect::that($reference)
            ->because('temporal construction details MUST stay internal')
            ->not()->toContain('PollingClock')
            ->not()->toContain('### `__construct()`');
    }

    #[Test]
    public function exceptionContractsUseDescriptivePublicSeamTypes(): void
    {
        Expect::that($this->reference('api-configuration.md'))
            ->toContain('## `InvalidConfiguration`')
            ->not()->toContain('ConfigurationError');
        Expect::that($this->reference('api-doubles.md'))
            ->toContain('## `InvalidDoubleUsage`')
            ->not()->toContain('DoublesError');
        Expect::that($this->reference('api-integrations.md'))
            ->toContain('@throws ServiceResolutionFailed')
            ->not()->toContain('ServiceResolutionError')
            ->not()->toContain('HyperfBridgeError')
            ->not()->toContain('LaravelBridgeError')
            ->not()->toContain('Psr11BridgeError')
            ->not()->toContain('SymfonyBridgeError')
            ->not()->toContain('TempestBridgeError');
        Expect::that($this->reference('api-test-contracts.md'))
            ->not()->toContain('WireError')
            ->not()->toContain('InvalidWirePayload')
            ->not()->toContain('WireCommunicationFailed')
            ->not()->toContain('WireSerializable');
        Expect::that($this->reference('api-reporting.md'))
            ->toContain('## `ReportGenerationFailed`')
            ->toContain('### `because()`')
            ->not()->toContain('### `writeFailed()`')
            ->not()->toContain('### `unmappedEvent()`')
            ->not()->toContain('### `xmlUnavailable()`')
            ->not()->toContain('ReportingError');
    }

    #[Test]
    public function eventDeclarationsExposeOnlyThePublicEventInterface(): void
    {
        Expect::that($this->reference('api-events.md'))
            ->because('built-in events MUST hide their internal wire interface')
            ->toContain('final readonly class RunStarted implements Event')
            ->toContain('final readonly class TestFinished implements Event')
            ->not()->toContain('WireEvent')
            ->not()->toContain('toWire()')
            ->not()->toContain('fromWire(');
    }

    private function reference(string $file): string
    {
        return (string) \file_get_contents(\dirname(__DIR__, 3) . '/docs/' . $file);
    }
}
