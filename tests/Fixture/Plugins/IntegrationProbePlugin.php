<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\Plugins;

use Greenlight\IntegrationFixture\FixtureResource;
use Greenlight\Harness\Scope;
use Greenlight\Harness\ServiceDefinition;
use Greenlight\Plugin\HarnessProvider;
use Greenlight\IntegrationFixture\IntegrationFixtureContext;
use Greenlight\IntegrationFixture\IntegrationFixtureDefinition;
use Greenlight\Plugin\IntegrationFixtureProvider;
use Greenlight\Plugin\WorkerBootstrapContext;
use Greenlight\Plugin\WorkerBootstrapSubscriber;

final class IntegrationProbePlugin implements IntegrationFixtureProvider, WorkerBootstrapSubscriber, HarnessProvider
{
    private ?FixtureResource $resource = null;

    public function __construct(
        private readonly string $markerDirectory,
        private readonly bool $failProvisioning = false,
        private readonly bool $failCleanup = false,
        private readonly ?int $failBootstrapChannel = null,
    ) {
        \file_put_contents($this->markerDirectory . '/constructed.log', "constructed\n", \FILE_APPEND);
    }

    #[\Override]
    public function integrationFixtures(): array
    {
        return [
            new IntegrationFixtureDefinition(
                'probe',
                function (IntegrationFixtureContext $context): void {
                    \file_put_contents(
                        $this->markerDirectory . '/provisioned.log',
                        $context->runId() . "\n",
                        \FILE_APPEND,
                    );

                    $channels = [];
                    $resourceFiles = [];

                    foreach ($context->channels() as $channel) {
                        $resourceFile = $this->markerDirectory . '/resource-' . $channel;
                        \file_put_contents($resourceFile, 'ready');
                        $resourceFiles[] = $resourceFile;
                        $channels[$channel] = FixtureResource::from(
                            values: [
                                'channel' => $channel,
                                'resourceFile' => $resourceFile,
                            ],
                            secrets: ['token' => 'fixture-secret-' . $channel],
                        );
                    }

                    $context->defer(function () use ($resourceFiles): void {
                        foreach ($resourceFiles as $resourceFile) {
                            if (\is_file($resourceFile)) {
                                \unlink($resourceFile);
                            }
                        }

                        \file_put_contents($this->markerDirectory . '/cleaned.log', "cleaned\n", \FILE_APPEND);

                        if ($this->failCleanup) {
                            throw new \RuntimeException('intentional fixture cleanup failure');
                        }
                    });
                    $context->expose(
                        FixtureResource::from(['run' => $context->runId()]),
                        $channels,
                    );

                    if ($this->failProvisioning) {
                        throw new \RuntimeException('intentional fixture provisioning failure');
                    }
                },
            ),
        ];
    }

    #[\Override]
    public function onWorkerBootstrap(WorkerBootstrapContext $context): void
    {
        $this->resource = $context->resources->fixture('probe');
        \file_put_contents(
            $this->markerDirectory . '/bootstrapped.log',
            $context->workerId . ':' . $context->channel->number . "\n",
            \FILE_APPEND,
        );

        if ($context->channel->number === $this->failBootstrapChannel) {
            throw new \RuntimeException('intentional worker bootstrap failure');
        }
    }

    #[\Override]
    public function services(): array
    {
        $resource = $this->resource ?? throw new \LogicException('Probe fixture was not bootstrapped before harness services.');

        return [
            new ServiceDefinition(
                IntegrationProbeService::class,
                Scope::PerWorker,
                static fn(): IntegrationProbeService => new IntegrationProbeService(
                    $resource->int('channel'),
                    $resource->string('resourceFile'),
                    $resource->secret('token')->reveal(),
                ),
            ),
        ];
    }
}
