<?php

declare(strict_types=1);

namespace Greenlight\Expect;

use Greenlight\Result\FailureDetail;
use Greenlight\Result\SourceLocation;

/**
 * Identifies one or more failed expectations.
 *
 * Contains structured `FailureDetail` values. The runner uses them to report
 * the expected value, actual value, and call site. It does not have to parse
 * the message.
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
        return new self([$detail], $detail->message . self::locationSuffix($detail));
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

        foreach ($details as $index => $detail) {
            $lines[] = \sprintf('%d) %s%s', $index + 1, $detail->message, self::locationSuffix($detail));
        }

        return new self($details, \implode("\n", $lines));
    }

    private static function locationSuffix(FailureDetail $detail): string
    {
        return $detail->location instanceof SourceLocation
            ? ' (at ' . $detail->location->__toString() . ')'
            : '';
    }

    /**
     * Returns the first failure. Use `$details` to read all failures, including
     * multiple unmet mock expectations.
     */
    public function detail(): FailureDetail
    {
        return $this->details[0];
    }
}
