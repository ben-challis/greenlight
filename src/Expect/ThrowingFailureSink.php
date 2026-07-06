<?php

declare(strict_types=1);

namespace Greenlight\Expect;

use Greenlight\Core\Result\FailureDetail;

/**
 * Default failure mode: the first failed expectation throws.
 *
 * @internal
 */
final class ThrowingFailureSink implements FailureSink
{
    #[\Override]
    public function report(FailureDetail $detail): void
    {
        throw ExpectationFailed::fromDetail($detail);
    }
}
