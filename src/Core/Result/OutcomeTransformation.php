<?php

declare(strict_types=1);

namespace Greenlight\Core\Result;

use Greenlight\Core\Wire\Wire;
use Greenlight\Core\Wire\WireSerializable;

/**
 * Provenance record for a plugin changing a test's outcome. Every transformation
 * is attributable in reports.
 *
 * @internal
 */
final readonly class OutcomeTransformation implements WireSerializable
{
    /**
     * @param non-empty-string $transformedBy
     */
    public function __construct(
        public string $transformedBy,
        public Outcome $from,
        public Outcome $to,
    ) {}

    #[\Override]
    public function toWire(): array
    {
        return [
            'transformedBy' => $this->transformedBy,
            'from' => $this->from->value,
            'to' => $this->to->value,
        ];
    }

    #[\Override]
    public static function fromWire(array $payload): static
    {
        return new self(
            Wire::nonEmptyString($payload, 'transformedBy'),
            Outcome::from(Wire::nonEmptyString($payload, 'from')),
            Outcome::from(Wire::nonEmptyString($payload, 'to')),
        );
    }
}
