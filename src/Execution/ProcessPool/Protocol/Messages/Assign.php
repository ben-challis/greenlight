<?php

declare(strict_types=1);

namespace Greenlight\Execution\ProcessPool\Protocol\Messages;

use Greenlight\Config\ArtifactConfiguration;
use Greenlight\Discovery\Plan\ExecutionPlan;
use Greenlight\Execution\Artifact\ArtifactSession;
use Greenlight\Execution\ProcessPool\Protocol\Message;
use Greenlight\Internal\Wire\Wire;
use Greenlight\Result\ResultPolicy;

/**
 * Sends an execution-plan part, limits, and settings to a worker.
 *
 * @internal
 */
final readonly class Assign implements Message
{
    /**
     * @param list<non-empty-string>|null $coverageInclude Null disables coverage.
     * @param non-empty-string|null $coverageDriver
     * @param positive-int|null $stopAfterFailures Remaining assignment failure allowance.
     */
    public function __construct(
        public ExecutionPlan $slice,
        public ?array $coverageInclude = null,
        public ?string $coverageDriver = null,
        public bool $detectLeaks = false,
        public ?ResultPolicy $policy = null,
        public ?ArtifactSession $artifactSession = null,
        public ?ArtifactConfiguration $artifactConfiguration = null,
        public ?int $stopAfterFailures = null,
    ) {}

    #[\Override]
    public static function tag(): string
    {
        return 'assign';
    }

    #[\Override]
    public function toWire(): array
    {
        return [
            'slice' => $this->slice->toWire(),
            'coverageInclude' => $this->coverageInclude,
            'coverageDriver' => $this->coverageDriver,
            'detectLeaks' => $this->detectLeaks,
            'policy' => $this->policy?->toWire(),
            'artifactSession' => $this->artifactSession?->toWire(),
            'artifactConfiguration' => $this->artifactConfiguration?->toWire(),
            'stopAfterFailures' => $this->stopAfterFailures,
        ];
    }

    #[\Override]
    public static function fromWire(array $payload): static
    {
        $coverageInclude = Wire::nullableListOfStrings($payload, 'coverageInclude');
        $coverageDriver = Wire::nullableString($payload, 'coverageDriver');
        $stopAfterFailures = \array_key_exists('stopAfterFailures', $payload)
            ? Wire::nullableInt($payload, 'stopAfterFailures')
            : null;

        if ($coverageInclude !== null) {
            $coverageInclude = \array_values(\array_filter($coverageInclude, static fn(string $path): bool => $path !== ''));
        }

        return new self(
            ExecutionPlan::fromWire(Wire::map($payload, 'slice')),
            $coverageInclude,
            $coverageDriver === '' ? null : $coverageDriver,
            Wire::bool($payload, 'detectLeaks'),
            ($policy = Wire::nullableMap($payload, 'policy')) === null ? null : ResultPolicy::fromWire($policy),
            ($artifacts = \array_key_exists('artifactSession', $payload) ? Wire::nullableMap($payload, 'artifactSession') : null) === null
                ? null
                : ArtifactSession::fromWire($artifacts),
            ($artifactConfiguration = \array_key_exists('artifactConfiguration', $payload) ? Wire::nullableMap($payload, 'artifactConfiguration') : null) === null
                ? null
                : ArtifactConfiguration::fromWire($artifactConfiguration),
            $stopAfterFailures === null ? null : \max(1, $stopAfterFailures),
        );
    }
}
