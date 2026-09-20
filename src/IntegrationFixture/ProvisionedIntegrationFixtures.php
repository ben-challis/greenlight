<?php

declare(strict_types=1);

namespace Greenlight\IntegrationFixture;

/**
 * Provisioned fixture data and its orchestrator-side cleanup stack.
 *
 * @internal
 */
final class ProvisionedIntegrationFixtures
{
    private const int MAX_CHANNEL_PAYLOAD_BYTES = 1_048_576;

    /**
     * @var array<non-empty-string, array{FixtureResource, array<int, FixtureResource>}>
     */
    private array $resources = [];

    /**
     * @var list<array{non-empty-string, \Closure(): void}>
     */
    private array $cleanups = [];

    private bool $closed = false;

    /**
     * @param non-empty-string $fixture
     * @param array<int, FixtureResource> $channels
     */
    public function expose(string $fixture, FixtureResource $shared, array $channels): void
    {
        $this->ensureOpen();

        if (isset($this->resources[$fixture])) {
            throw new \LogicException(\sprintf('Integration fixture "%s" exposed resources more than once.', $fixture));
        }

        $this->resources[$fixture] = [$shared, $channels];
    }

    /**
     * @param non-empty-string $fixture
     */
    public function ensureExposed(string $fixture): void
    {
        $this->ensureOpen();

        $this->resources[$fixture] ??= [FixtureResource::empty(), []];
    }

    public function dependency(string $fixture, ?int $channel): FixtureResource
    {
        [$shared, $channels] = $this->resources[$fixture] ?? throw new \LogicException(\sprintf(
            'Integration fixture dependency "%s" has not been provisioned.',
            $fixture,
        ));

        if ($channel === null) {
            return $shared;
        }

        return $shared->mergedWith($channels[$channel] ?? FixtureResource::empty());
    }

    /**
     * @param non-empty-string $fixture
     * @param \Closure(): void $cleanup
     */
    public function defer(string $fixture, \Closure $cleanup): void
    {
        $this->ensureOpen();
        $this->cleanups[] = [$fixture, $cleanup];
    }

    public function forChannel(int $channel): IntegrationResources
    {
        $fixtures = [];

        foreach ($this->resources as $id => [$shared, $channels]) {
            $fixtures[$id] = $shared->mergedWith($channels[$channel] ?? FixtureResource::empty());
        }

        return new IntegrationResources($fixtures);
    }

    /**
     * @param non-empty-list<positive-int> $channels
     */
    public function validateTransport(array $channels): void
    {
        foreach ($channels as $channel) {
            $payload = \json_encode(
                $this->forChannel($channel)->toWire(),
                \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE,
            );

            if (\strlen($payload) > self::MAX_CHANNEL_PAYLOAD_BYTES) {
                throw new \LengthException(\sprintf(
                    'Integration resources for channel %d exceed the 1 MiB transport limit.',
                    $channel,
                ));
            }
        }
    }

    /**
     * Runs every cleanup in reverse acquisition order.
     *
     * @return list<array{non-empty-string, \Throwable}>
     */
    public function close(): array
    {
        if ($this->closed) {
            return [];
        }

        $this->closed = true;
        $failures = [];

        foreach (\array_reverse($this->cleanups) as [$fixture, $cleanup]) {
            try {
                $cleanup();
            } catch (\Throwable $failure) {
                $failures[] = [$fixture, $failure];
            }
        }

        $this->cleanups = [];

        return $failures;
    }

    private function ensureOpen(): void
    {
        if ($this->closed) {
            throw new \LogicException('Integration fixture session is already closed.');
        }
    }
}
