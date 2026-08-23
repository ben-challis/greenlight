<?php

declare(strict_types=1);

namespace Greenlight\Result;

use Greenlight\Internal\Wire\Wire;
use Greenlight\Internal\Wire\WireCommunicationFailed;

/** Records the source of a plugin change to a test outcome. */
final readonly class OutcomeTransformation
{
    /**
     * @var non-empty-string
     */
    public string $transformedBy;

    /**
     * @throws \InvalidArgumentException
     */
    public function __construct(
        string $transformedBy,
        public Outcome $from,
        public Outcome $to,
    ) {
        if ($transformedBy === '') {
            throw new \InvalidArgumentException('Outcome transformation source must not be empty.');
        }

        $this->transformedBy = $transformedBy;
    }

    /**
     * @internal
     *
     * @return array<string, mixed>
     */
    public function toWire(): array
    {
        return [
            'transformedBy' => $this->transformedBy,
            'from' => $this->from->value,
            'to' => $this->to->value,
        ];
    }

    /**
     * @internal
     *
     * @param array<string, mixed> $payload
     * @throws WireCommunicationFailed
     */
    public static function fromWire(array $payload): static
    {
        return new self(
            Wire::nonEmptyString($payload, 'transformedBy'),
            Wire::enum($payload, 'from', Outcome::class),
            Wire::enum($payload, 'to', Outcome::class),
        );
    }
}
