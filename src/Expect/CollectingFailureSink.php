<?php

declare(strict_types=1);

namespace Greenlight\Expect;

use Greenlight\Core\Result\FailureDetail;

/**
 * Soft failure mode: failures accumulate so Expect::softly() can throw one
 * aggregate at the end.
 *
 * @internal
 */
final class CollectingFailureSink implements FailureSink
{
    /**
     * @var list<FailureDetail>
     */
    private array $details = [];

    #[\Override]
    public function report(FailureDetail $detail): void
    {
        $this->details[] = $detail;
    }

    /**
     * @return list<FailureDetail>
     */
    public function details(): array
    {
        return $this->details;
    }
}
