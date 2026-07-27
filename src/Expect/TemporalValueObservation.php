<?php

declare(strict_types=1);

namespace Greenlight\Expect;

use Greenlight\Core\Result\FailureDetail;

/**
 * Records one probe value and its matcher result.
 *
 * @internal
 *
 * @template T
 */
final readonly class TemporalValueObservation extends TemporalObservation
{
    /**
     * @param T $subject
     */
    public function __construct(
        public mixed $subject,
        bool $matched,
        ?FailureDetail $failure,
        string $rendered,
    ) {
        parent::__construct($matched, $failure, null, $rendered);
    }
}
