<?php

declare(strict_types=1);

namespace Greenlight\IntegrationFixture;

/**
 * Supplies orchestrator-side operations while Greenlight provisions one
 * integration fixture.
 */
interface IntegrationFixtureContext
{
    /**
     * @return non-empty-string
     */
    public function runId(): string;

    /**
     * @return positive-int
     */
    public function configuredWorkers(): int;

    /**
     * The channel numbers that can be live during this execution.
     *
     * @return non-empty-list<positive-int>
     */
    public function channels(): array;

    /**
     * @return array{int, int}|null one-based shard index and shard count
     */
    public function shard(): ?array;

    /**
     * Gets the shared resource or the resource for one channel.
     *
     * @throws \LogicException when the fixture does not declare the dependency
     * @throws \OutOfBoundsException when the channel is not part of this run
     */
    public function dependency(string $id, ?int $channel = null): FixtureResource;

    /**
     * Register cleanup immediately after the provisioner acquires a resource.
     * This makes cleanup available if a later operation fails.
     *
     * @param \Closure(): void $cleanup
     */
    public function defer(\Closure $cleanup): void;

    /**
     * Publishes shared data plus optional channel-specific overlays.
     *
     * @param array<int, FixtureResource> $channels keyed by channel number
     * @throws \InvalidArgumentException when a channel is not part of this run
     * @throws \LogicException when the provisioner publishes resources more than once
     */
    public function expose(FixtureResource $shared, array $channels = []): void;
}
