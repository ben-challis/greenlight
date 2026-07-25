<?php

declare(strict_types=1);

namespace Greenlight\Runner\Protocol\Messages;

use Greenlight\Core\Wire\Wire;
use Greenlight\Harness\IntegrationResources;
use Greenlight\Runner\Protocol\Message;

/**
 * Orchestrator to worker: immutable worker-lifetime configuration.
 *
 * @internal
 */
final readonly class Bootstrap implements Message
{
    /**
     * @param positive-int $channel
     * @param non-empty-string|null $configFile
     */
    public function __construct(
        public int $channel,
        public ?string $configFile,
        public IntegrationResources $resources,
    ) {}

    #[\Override]
    public static function tag(): string
    {
        return 'bootstrap';
    }

    #[\Override]
    public function toWire(): array
    {
        return [
            'channel' => $this->channel,
            'configFile' => $this->configFile,
            'resources' => $this->resources->toWire(),
        ];
    }

    #[\Override]
    public static function fromWire(array $payload): static
    {
        $configFile = Wire::nullableString($payload, 'configFile');

        return new self(
            \max(1, Wire::int($payload, 'channel')),
            $configFile === '' ? null : $configFile,
            IntegrationResources::fromWire(Wire::map($payload, 'resources')),
        );
    }
}
