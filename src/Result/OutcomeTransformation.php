<?php

declare(strict_types=1);

namespace Greenlight\Result;

use Greenlight\Wire\Wire;
use Greenlight\Wire\WireSerializable;

/** Records the source of a plugin change to a test outcome. */
final readonly class OutcomeTransformation implements WireSerializable
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
