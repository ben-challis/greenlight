<?php

declare(strict_types=1);

namespace Greenlight\Expect;

use Greenlight\Core\Result\FailureDetail;
use Greenlight\Core\Result\SourceLocation;

/**
 * Thrown when one or more expectations fail. Carries the structured
 * FailureDetail values so the runner can report rendered expected/actual
 * strings and the call site without re-parsing the message.
 */
final class ExpectationFailed extends \Exception
{
    /**
     * @param non-empty-list<FailureDetail> $details
     * @param non-empty-string $message
     */
    private function __construct(
        public readonly array $details,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function fromDetail(FailureDetail $detail): self
    {
        $message = $detail->message;

        if ($detail->location instanceof SourceLocation) {
            $message .= ' (at ' . $detail->location->__toString() . ')';
        }

        return new self([$detail], $message);
    }

    /**
     * @param non-empty-list<FailureDetail> $details
     */
    public static function fromDetails(array $details): self
    {
        if (\count($details) === 1) {
            return self::fromDetail($details[0]);
        }

        $lines = [\sprintf('%d expectations failed:', \count($details))];
        $number = 0;

        foreach ($details as $detail) {
            ++$number;
            $suffix = $detail->location === null ? '' : ' (at ' . $detail->location->__toString() . ')';
            $lines[] = \sprintf('%d) %s%s', $number, $detail->message, $suffix);
        }

        return new self($details, \implode("\n", $lines));
    }

    /**
     * The first failure, which for the default throw-on-first-failure mode is
     * the only one.
     */
    public function detail(): FailureDetail
    {
        return $this->details[0];
    }
}
