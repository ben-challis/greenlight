<?php

declare(strict_types=1);

namespace Greenlight\Runner\Integration;

use Greenlight\Harness\FixtureResource;
use Greenlight\Plugin\IntegrationFixtureContext;

/**
 * @internal
 */
final readonly class FixtureProvisioningContext implements IntegrationFixtureContext
{
    /**
     * @param non-empty-string $fixture
     * @param list<non-empty-string> $dependencies
     * @param non-empty-string $runIdentifier
     * @param positive-int $configuredWorkerCount
     * @param non-empty-list<positive-int> $channelNumbers
     * @param array{int, int}|null $shardIdentity
     */
    public function __construct(
        private string $fixture,
        private array $dependencies,
        private string $runIdentifier,
        private int $configuredWorkerCount,
        private array $channelNumbers,
        private ?array $shardIdentity,
        private ProvisionedIntegrationFixtures $session,
    ) {}

    #[\Override]
    public function runId(): string
    {
        return $this->runIdentifier;
    }

    #[\Override]
    public function configuredWorkers(): int
    {
        return $this->configuredWorkerCount;
    }

    #[\Override]
    public function channels(): array
    {
        return $this->channelNumbers;
    }

    #[\Override]
    public function shard(): ?array
    {
        return $this->shardIdentity;
    }

    #[\Override]
    public function dependency(string $id, ?int $channel = null): FixtureResource
    {
        if (!\in_array($id, $this->dependencies, true)) {
            throw new \LogicException(\sprintf(
                'Integration fixture "%s" cannot access undeclared dependency "%s".',
                $this->fixture,
                $id,
            ));
        }

        if ($channel !== null && !\in_array($channel, $this->channelNumbers, true)) {
            throw new \OutOfBoundsException(\sprintf('Channel %d is not part of this integration fixture run.', $channel));
        }

        return $this->session->dependency($id, $channel);
    }

    #[\Override]
    public function defer(\Closure $cleanup): void
    {
        $this->session->defer($this->fixture, $cleanup);
    }

    #[\Override]
    public function expose(FixtureResource $shared, array $channels = []): void
    {
        foreach (\array_keys($channels) as $channel) {
            if (!\in_array($channel, $this->channelNumbers, true)) {
                throw new \InvalidArgumentException(\sprintf(
                    'Integration fixture "%s" exposed an invalid channel resource.',
                    $this->fixture,
                ));
            }
        }

        $this->session->expose($this->fixture, $shared, $channels);
    }
}
