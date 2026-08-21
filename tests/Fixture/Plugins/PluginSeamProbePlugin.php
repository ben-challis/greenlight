<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\Plugins;

use Greenlight\Harness\FixtureResource;
use Greenlight\Harness\Scope;
use Greenlight\Harness\ServiceDefinition;
use Greenlight\Plugin\HarnessProvider;
use Greenlight\Plugin\IntegrationFixtureContext;
use Greenlight\Plugin\IntegrationFixtureDefinition;
use Greenlight\Plugin\IntegrationFixtureProvider;
use Greenlight\Plugin\WorkerBootstrapContext;
use Greenlight\Plugin\WorkerBootstrapSubscriber;

final class PluginSeamProbePlugin implements IntegrationFixtureProvider, WorkerBootstrapSubscriber, HarnessProvider
{
    private string $property = 'worker-fresh';

    private ?string $integrationResource = null;

    public function __construct(private readonly string $markerDirectory)
    {
        \file_put_contents($this->markerDirectory . '/constructed.log', "constructed\n", \FILE_APPEND);
    }

    #[\Override]
    public function integrationFixtures(): array
    {
        $this->property = 'orchestrator-private';

        return [
            new IntegrationFixtureDefinition(
                'plugin-seam',
                static function (IntegrationFixtureContext $context): void {
                    $context->expose(
                        FixtureResource::from(['transfer' => 'integration-resource']),
                    );
                },
            ),
        ];
    }

    #[\Override]
    public function onWorkerBootstrap(WorkerBootstrapContext $context): void
    {
        if ($this->property !== 'worker-fresh') {
            throw new \RuntimeException('An orchestrator plugin property crossed the worker seam.');
        }

        $this->integrationResource = $context->resources->fixture('plugin-seam')->string('transfer');
    }

    #[\Override]
    public function services(): array
    {
        $integrationResource = $this->integrationResource
            ?? throw new \LogicException('The integration resource was not installed during worker bootstrap.');

        return [
            new ServiceDefinition(
                PluginSeamProbe::class,
                Scope::PerRun,
                fn(): PluginSeamProbe => new PluginSeamProbe($this->property, $integrationResource),
            ),
        ];
    }
}
