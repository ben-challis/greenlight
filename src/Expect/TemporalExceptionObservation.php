<?php

declare(strict_types=1);

namespace Greenlight\Expect;

/**
 * Records a probe exception that an eventual expectation can retry.
 *
 * @internal
 */
final readonly class TemporalExceptionObservation extends TemporalObservation
{
    public function __construct(\Exception $exception, string $rendered)
    {
        parent::__construct(false, null, $exception, $rendered);
    }
}
