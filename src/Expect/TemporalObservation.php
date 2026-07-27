<?php

declare(strict_types=1);

namespace Greenlight\Expect;

use Greenlight\Core\Result\FailureDetail;

/**
 * Records the result of one poll attempt.
 *
 * @internal
 */
abstract readonly class TemporalObservation
{
    protected function __construct(
        public bool $matched,
        public ?FailureDetail $failure,
        public ?\Exception $exception,
        public string $rendered,
    ) {}

    /**
     * @template T
     *
     * @param T $subject
     *
     * @return TemporalValueObservation<T>
     */
    public static function matched(mixed $subject, string $rendered): TemporalValueObservation
    {
        return new TemporalValueObservation($subject, true, null, $rendered);
    }

    /**
     * @template T
     *
     * @param T $subject
     *
     * @return TemporalValueObservation<T>
     */
    public static function failed(mixed $subject, FailureDetail $failure, string $rendered): TemporalValueObservation
    {
        return new TemporalValueObservation($subject, false, $failure, $rendered);
    }

    public static function threw(\Exception $exception, string $rendered): TemporalExceptionObservation
    {
        return new TemporalExceptionObservation($exception, $rendered);
    }
}
