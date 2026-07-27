<?php

declare(strict_types=1);

namespace Greenlight\Core\Result;

use Greenlight\Core\Wire\Wire;
use Greenlight\Core\Wire\WireSerializable;

/** Records the source of a plugin change to a test outcome. */
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
            Wire::enum($payload, 'from', Outcome::class),
            Wire::enum($payload, 'to', Outcome::class),
        );
    }
}
