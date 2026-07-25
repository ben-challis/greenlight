<?php

declare(strict_types=1);

namespace Greenlight\Expect;

use Greenlight\Core\Result\FailureDetail;

/**
 * The result of sampling a probe and applying one matcher.
 *
 * @internal
 */
final readonly class TemporalObservation
{
    private function __construct(
        public mixed $subject,
        public bool $matched,
        public ?FailureDetail $failure,
        public ?\Exception $exception,
        public string $rendered,
    ) {}

    public static function matched(mixed $subject, string $rendered): self
    {
        return new self($subject, true, null, null, $rendered);
    }

    public static function failed(mixed $subject, FailureDetail $failure, string $rendered): self
    {
        return new self($subject, false, $failure, null, $rendered);
    }

    public static function threw(\Exception $exception, string $rendered): self
    {
        return new self(null, false, null, $exception, $rendered);
    }
}
