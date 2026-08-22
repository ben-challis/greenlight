<?php

declare(strict_types=1);

namespace Greenlight\Execution\ProcessPool\Protocol\Messages;

use Greenlight\Execution\ProcessPool\Protocol\Message;
use Greenlight\IntegrationFixture\IntegrationResources;
use Greenlight\Internal\Wire\Wire;

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
        public ?string $generatedCodeDirectory = null,
        public ?string $temporaryDirectory = null,
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
            'generatedCodeDirectory' => $this->generatedCodeDirectory,
            'temporaryDirectory' => $this->temporaryDirectory,
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
            ($generatedCode = \array_key_exists('generatedCodeDirectory', $payload)
                ? Wire::nullableString($payload, 'generatedCodeDirectory')
                : null) === '' ? null : $generatedCode,
            ($temporary = \array_key_exists('temporaryDirectory', $payload)
                ? Wire::nullableString($payload, 'temporaryDirectory')
                : null) === '' ? null : $temporary,
        );
    }
}
