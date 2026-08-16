<?php

declare(strict_types=1);

namespace Greenlight\Plugin;

use Greenlight\Harness\FixtureResource;

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
     */
    public function expose(FixtureResource $shared, array $channels = []): void;
}
