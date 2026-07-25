<?php

declare(strict_types=1);

namespace Greenlight\Plugin;

use Greenlight\Harness\FixtureResource;

/**
 * Orchestrator-side context used while one integration fixture is provisioned.
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

    public function dependency(string $id, ?int $channel = null): FixtureResource;

    /**
     * Registers cleanup immediately. Call this as soon as a real resource is
     * acquired so later failures in the same provisioner cannot leak it.
     *
     * @param \Closure(): void $cleanup
     */
    public function defer(\Closure $cleanup): void;

    /**
     * Publishes shared data plus optional channel-specific overlays.
     *
     * @param array<int, FixtureResource> $channels keyed by channel number
     */
    public function expose(FixtureResource $shared, array $channels = []): void;
}
